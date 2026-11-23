<?php

namespace App\Modules\Service\Console;

use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Services\ReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * مهام الصيانة (02 §9)، كلها متكررة الأمان: J-04 تنبيه واحد لكل طلب تجاوز مهلة الفرز (BR-09، N-09)،
 * و J-05 دعوة التقييم (N-15)، و J-12 تذكير الاستلام من الورشة (BR-20، N-12).
 */
class ServiceJobs extends Command
{
    protected $signature = 'service:run {job : sla|reviews|pickup}';

    protected $description = 'J-04 triage SLA alert, J-05 review invitations, J-12 pickup reminders';

    public function handle(ReviewService $reviews): int
    {
        $job = $this->argument('job');
        if ($job === 'reviews') {
            $this->info('invited '.$reviews->inviteDue());

            return self::SUCCESS;
        }
        if ($job === 'pickup') {
            $this->info('reminded '.$reviews->remindPickups());

            return self::SUCCESS;
        }
        if ($job !== 'sla') {
            $this->error('job must be sla, reviews or pickup');

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
