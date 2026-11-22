<?php

namespace App\Modules\Accounts\Console;

use App\Modules\Accounts\Models\DataExport;
use App\Modules\Accounts\Services\Anonymizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** J-08 تجهيل الحسابات المحذوفة، وإتلاف نسخ البيانات المنتهية (02 §9). كلاهما يومي ومتكرر الأمان. */
class AccountJobs extends Command
{
    protected $signature = 'accounts:run {job : anonymize|purge-exports}';

    protected $description = 'J-08 anonymize deleted accounts, purge expired data exports';

    public function handle(Anonymizer $anonymizer): int
    {
        $n = match ($this->argument('job')) {
            'anonymize' => $anonymizer->runDue(),
            'purge-exports' => $this->purgeExports(),
            default => -1,
        };
        if ($n < 0) {
            $this->error('job must be anonymize or purge-exports');

            return self::INVALID;
        }
        $this->info("{$this->argument('job')}: {$n}");

        return self::SUCCESS;
    }

    private function purgeExports(): int
    {
        $due = DataExport::query()->where('status', 'ready')->where('expires_at', '<=', now())->with('attachment')->get();
        foreach ($due as $export) {
            if ($export->attachment) {
                Storage::disk('local')->delete($export->attachment->storage_key);
            }
            $export->update(['status' => 'expired']);
        }

        return $due->count();
    }
}
