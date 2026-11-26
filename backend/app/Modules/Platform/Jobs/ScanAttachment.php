<?php

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Security\VirusScanner;
use App\Modules\Platform\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * فحص الملف المرفوع (NFR-11): يبقى pending لا يُعرض ولا يُربط بروابط موقعة حتى ينتهي الفحص. المصاب يُحذف محتواه
 * ويُعلَّم infected ويُسجَّل في التدقيق. تعذر الوصول للفاحص يعيد المحاولة بفواصل متزايدة.
 */
class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public string $attachmentId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 1200, 3600];
    }

    public function handle(VirusScanner $scanner): void
    {
        $a = Attachment::query()->find($this->attachmentId);
        if (! $a || $a->scan_status !== 'pending') {
            return;
        }
        $disk = Storage::disk($a->visibility === 'public' ? 'public' : 'local');
        $bytes = $disk->get($a->storage_key);
        if ($bytes === null) {
            return;
        }
        $signature = $scanner->scan($bytes);
        if ($signature === null) {
            $a->forceFill(['scan_status' => 'clean'])->save();

            return;
        }
        $disk->delete($a->storage_key);
        $a->forceFill(['scan_status' => 'infected'])->save();
        Audit::record('file.infected', 'attachment', $a->id, null, ['signature' => $signature, 'uploaded_by' => $a->uploaded_by]);
        Log::warning('infected upload removed', ['attachment' => $a->id, 'signature' => $signature]);
    }
}
