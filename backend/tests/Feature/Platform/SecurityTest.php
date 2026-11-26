<?php

use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Security\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// NFR-06 (ترويسات الأمان)، NFR-11 (فحص الملفات)، NFR-04 و NFR-20 (فحص الصحة)

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

/** فاحص وهمي: ما يحمل توقيع EICAR مصاب */
function fakeScanner(): void
{
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $bytes): ?string
        {
            return str_contains($bytes, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') ? 'Eicar-Test-Signature' : null;
        }
    });
}

it('scans uploads before they can be served, and removes infected ones (NFR-11)', function () {
    config(['services.antivirus.driver' => 'clamav']);
    fakeScanner();
    $c = customerToken($this);

    $clean = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->createWithContent('po.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    expect(Attachment::query()->findOrFail($clean)->scan_status)->toBe('clean');

    $bad = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->createWithContent('po.pdf',
        "%PDF-1.4\nX5O!P%@AP[4\\PZX54(P^)7CC)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!\$H+H*\n%%EOF")], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $a = Attachment::query()->findOrFail($bad);
    expect($a->scan_status)->toBe('infected')->and(Storage::disk('local')->exists($a->storage_key))->toBeFalse()
        ->and($a->accessUrl())->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'file.infected')->count())->toBe(1);
});

it('keeps a file pending, and unservable, while the scanner is unreachable (NFR-11)', function () {
    config(['services.antivirus.driver' => 'clamav', 'services.antivirus.host' => '127.0.0.1', 'services.antivirus.port' => 1]);
    $c = customerToken($this);
    $id = null;
    try {
        $id = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->json('data.id');
    } catch (Throwable) {
        // المهمة المتزامنة في الاختبار ترمي بعد الحفظ
    }
    $a = Attachment::query()->latest('created_at')->firstOrFail();
    expect($a->scan_status)->toBe('pending')->and($a->accessUrl())->toBeNull();
});

it('sends security headers on API responses and no-store on private ones (NFR-06)', function () {
    $public = $this->getJson(api().'/public/branches')->assertOk();
    expect($public->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($public->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($public->headers->get('X-Trace-Id'))->not->toBeNull();

    $c = customerToken($this);
    $me = asUser($this, $c)->getJson(api().'/me')->assertOk();
    expect($me->headers->get('Cache-Control'))->toContain('no-store');
});

it('reports health for external monitoring without secrets (NFR-04, NFR-20)', function () {
    Cache::put('health:scheduler', now()->toIso8601ZuluString(), now()->addHour());
    $h = $this->getJson(api().'/health')->assertOk()->json();
    expect($h['status'])->toBe('ok')->and(array_keys($h['checks']))->toBe(['database', 'redis', 'storage', 'queue', 'scheduler'])
        ->and(json_encode($h))->not->toContain('password');

    Cache::forget('health:scheduler');
    expect($this->getJson(api().'/health')->assertOk()->json('status'))->toBe('degraded');
});
