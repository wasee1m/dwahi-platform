<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\OrderService;
use Illuminate\Console\Command;

/** J-01 (02 §9): تذكير الاستلام قبل المهلة بيوم، وإلغاء غير المستلم بعدها (BR-05). متكرر الأمان. */
class OrderJobs extends Command
{
    protected $signature = 'orders:run {job : expire}';

    protected $description = 'J-01 remind and cancel parts orders not collected within the pickup hold';

    public function handle(OrderService $orders): int
    {
        if ($this->argument('job') !== 'expire') {
            $this->error('job must be expire');

            return self::FAILURE;
        }
        $r = $orders->expireDue();
        $this->info("reminded {$r['reminded']}, cancelled {$r['cancelled']}");

        return self::SUCCESS;
    }
}
