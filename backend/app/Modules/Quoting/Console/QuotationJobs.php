<?php

namespace App\Modules\Quoting\Console;

use App\Modules\Quoting\Services\QuotationService;
use Illuminate\Console\Command;

/** J-02 انتهاء صلاحية العروض، و J-03 تذكير قبل 48 ساعة (02 §9). كلاهما كل ساعة ومتكرر الأمان. */
class QuotationJobs extends Command
{
    protected $signature = 'quotations:run {job : expire|remind}';

    protected $description = 'J-02 expire due quotations, J-03 remind 48h before expiry';

    public function handle(QuotationService $quotes): int
    {
        $n = match ($this->argument('job')) {
            'expire' => $quotes->expireDue(),
            'remind' => $quotes->remindExpiring(),
            default => -1,
        };
        if ($n < 0) {
            $this->error('job must be expire or remind');

            return self::INVALID;
        }
        $this->info("{$this->argument('job')}: {$n}");

        return self::SUCCESS;
    }
}
