<?php

namespace App\Modules\Service\Console;

use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** J-04 (02 §9): تنبيه واحد لكل طلب صيانة تجاوز مهلة الفرز دون فرز (BR-09، N-09). متكرر الأمان. */
class ServiceJobs extends Command
{
    protected $signature = 'service:run {job : sla}';

    protected $description = 'J-04 alert on service requests past their triage SLA';

    public function handle(): int
    {
        if ($this->argument('job') !== 'sla') {
            $this->error('job must be sla');

            return self::FAILURE;
        }
        $n = 0;
        ServiceRequest::query()->where('status', 'submitted')->whereNull('sla_alerted_at')->where('sla_due_at', '<=', now())
            ->pluck('id')->each(function ($id) use (&$n) {
                $sr = DB::transaction(function () use ($id) {
                    $sr = ServiceRequest::query()->lockForUpdate()->find($id);
                    if (! $sr || $sr->status !== 'submitted' || $sr->sla_alerted_at) {
                        return null;
                    }
                    $sr->update(['sla_alerted_at' => now()]);

                    return $sr;
                });
                if ($sr) {
                    $n++;
                    ServiceEvent::dispatch(ServiceEvent::SLA_BREACHED, $sr);
                }
            });
        $this->info("alerted {$n}");

        return self::SUCCESS;
    }
}
