<?php

namespace App\Modules\Accounts\Jobs;

use App\Modules\Accounts\Events\DataExportReady;
use App\Modules\Accounts\Models\DataExport;
use App\Modules\Accounts\Services\DataExportBuilder;
use App\Modules\Platform\Services\Uploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** تجهيز نسخة البيانات في الطابور (US-11: خلال 24 ساعة كحد أقصى). متكرر الأمان. */
class BuildDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public string $exportId) {}

    public function handle(DataExportBuilder $builder, Uploads $uploads): void
    {
        $export = DataExport::query()->with('user')->find($this->exportId);
        if (! $export || $export->status !== 'pending' || $export->user->status !== 'active') {
            return;
        }
        $json = json_encode($builder->build($export->user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        // اسم الملف بلا بيانات شخصية
        $file = $uploads->storeGenerated($json, 'application/json', 'my-data-'.now()->format('Ymd').'.json', $export->user);
        $export->update([
            'status' => 'ready', 'attachment_id' => $file->id,
            'completed_at' => now(), 'expires_at' => now()->addDays(DataExport::KEEP_DAYS),
        ]);
        DataExportReady::dispatch($export);
    }

    public function failed(?Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->where('status', 'pending')->update(['status' => 'failed']);
    }
}
