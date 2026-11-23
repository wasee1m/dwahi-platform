<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * التقييم (BR-10، US-20): مرة واحدة لكل طلب مغلق، خلال مدة الإعداد review.window_days من الإغلاق.
 * المهمتان J-05 (دعوة بعد 24 ساعة، N-15) و J-12 (تذكير بالاستلام، N-12).
 */
final class ReviewService
{
    public static function windowDays(): int
    {
        return (int) Setting::read('review.window_days', 30);
    }

    /** يمكن التقييم الآن؟ */
    public static function open(ServiceRequest $sr): bool
    {
        return $sr->status === 'closed' && $sr->closed_at && $sr->closed_at->gt(now()->subDays(self::windowDays()))
            && ! DB::table('reviews')->where('service_request_id', $sr->id)->exists();
    }

    /** @param array{rating: int, timeliness_rating?: int|null, quality_rating?: int|null, communication_rating?: int|null, comment?: string|null, publish_consent?: bool} $data */
    public function submit(ServiceRequest $sr, User $customer, array $data): object
    {
        return DB::transaction(function () use ($sr, $customer, $data) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
            if (DB::table('reviews')->where('service_request_id', $sr->id)->exists()) {
                throw new ApiProblem('ALREADY_REVIEWED', 409);
            }
            if (! self::open($sr)) {
                throw new ApiProblem('REVIEW_CLOSED', 409);
            }
            $id = (string) Str::uuid7();
            DB::table('reviews')->insert([
                'id' => $id, 'service_request_id' => $sr->id, 'customer_user_id' => $customer->id, 'rating' => $data['rating'],
                'timeliness_rating' => $data['timeliness_rating'] ?? null, 'quality_rating' => $data['quality_rating'] ?? null,
                'communication_rating' => $data['communication_rating'] ?? null, 'comment' => $data['comment'] ?? null,
                'publish_consent' => (bool) ($data['publish_consent'] ?? false), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('service.reviewed', 'service_request', $sr->id, null, ['rating' => $data['rating']], $customer);

            return DB::table('reviews')->where('id', $id)->first();
        });
    }

    /** J-05 كل ساعة: دعوة واحدة بعد 24 ساعة من الإغلاق، ما دام التقييم ممكناً */
    public function inviteDue(): int
    {
        $n = 0;
        ServiceRequest::query()->where('status', 'closed')->whereNull('review_invited_at')
            ->where('closed_at', '<=', now()->subDay())->where('closed_at', '>', now()->subDays(self::windowDays()))
            ->whereNotIn('id', DB::table('reviews')->select('service_request_id'))
            ->pluck('id')->each(function ($id) use (&$n) {
                $sr = DB::transaction(function () use ($id) {
                    $sr = ServiceRequest::query()->lockForUpdate()->find($id);
                    if (! $sr || $sr->review_invited_at) {
                        return null;
                    }
                    $sr->update(['review_invited_at' => now()]);

                    return $sr;
                });
                if ($sr) {
                    $n++;
                    ServiceEvent::dispatch(ServiceEvent::REVIEW_INVITE, $sr);
                }
            });

        return $n;
    }

    /** J-12 يومياً: الجاهز غير المستلم بعد service.collection_reminder_days، ثم كل مثلها (BR-20) */
    public function remindPickups(): int
    {
        $days = max(1, (int) Setting::read('service.collection_reminder_days', 3));
        $n = 0;
        ServiceRequest::query()->where('status', 'completed')->where('completed_at', '<=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('pickup_reminded_at')->orWhere('pickup_reminded_at', '<=', now()->subDays($days)))
            ->pluck('id')->each(function ($id) use (&$n, $days) {
                $sr = DB::transaction(function () use ($id, $days) {
                    $sr = ServiceRequest::query()->lockForUpdate()->find($id);
                    if (! $sr || $sr->status !== 'completed' || ($sr->pickup_reminded_at && $sr->pickup_reminded_at->gt(now()->subDays($days)))) {
                        return null;
                    }
                    $sr->update(['pickup_reminded_at' => now()]);

                    return $sr;
                });
                if ($sr) {
                    $n++;
                    ServiceEvent::dispatch(ServiceEvent::PICKUP_REMINDER, $sr);
                }
            });

        return $n;
    }
}
