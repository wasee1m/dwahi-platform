<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Services\StockService;
use App\Modules\Platform\Support\Audit;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Models\WorkshopIntake;
use App\Support\ApiProblem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تنفيذ أمر العمل من الفني (SM-5 5.7 إلى 5.9، FR-TEC-03، FR-TEC-04، FR-WO-04). الفني يغيّر حالة ما أُسند إليه فقط،
 * ولا يكون له إلا أمر واحد جارٍ. وقت العمل يحسبه الخادم من الحالات (04 §6.4). الإنهاء (5.10) مع S9.
 */
final class ExecutionService
{
    public const HOLD_REASONS = ['awaiting_parts', 'awaiting_approval', 'awaiting_customer', 'end_of_shift', 'other'];

    public const PHASES = ['before', 'during', 'after', 'hour_meter', 'damage', 'test'];

    public function __construct(private readonly StockService $stock) {}

    public function isAssigned(WorkOrder $wo, User $user): bool
    {
        return DB::table('work_order_assignments')->where('work_order_id', $wo->id)->where('technician_user_id', $user->id)->whereNull('unassigned_at')->exists();
    }

    /** 5.7 و 5.9: بدأت، أو استئناف بعد إيقاف */
    public function start(WorkOrder $wo, User $user, CarbonInterface $at, ?string $clientEventId = null): WorkOrder
    {
        return DB::transaction(function () use ($wo, $user, $at, $clientEventId) {
            $wo = $this->lockAssigned($wo, $user);
            if (! in_array($wo->status, ['scheduled', 'on_hold'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $wo->status, 'to' => 'in_progress']);
            }
            if ($wo->status === 'scheduled') {
                if (! WorkshopIntake::query()->where('service_request_id', $wo->service_request_id)->exists()) {
                    throw new ApiProblem('NOT_RECEIVED', 409); // BR-20
                }
                if ($wo->scheduled_start && $wo->scheduled_start->timezone('Asia/Muscat')->startOfDay()->gt(now('Asia/Muscat')->startOfDay())) {
                    throw new ApiProblem('NOT_TODAY', 409, null, ['scheduled_start' => $wo->scheduled_start->toIso8601ZuluString()]);
                }
            }
            $active = WorkOrder::query()->where('status', 'in_progress')->where('id', '!=', $wo->id)
                ->whereIn('id', DB::table('work_order_assignments')->where('technician_user_id', $user->id)->whereNull('unassigned_at')->select('work_order_id'))
                ->value('number');
            if ($active) {
                throw new ApiProblem('ANOTHER_TASK_ACTIVE', 409, null, ['work_order' => $active]);
            }
            $from = $wo->status;
            $wo->update(['status' => 'in_progress', 'on_hold_reason' => null]);
            DB::table('time_entries')->insert(['id' => (string) Str::uuid7(), 'work_order_id' => $wo->id, 'technician_user_id' => $user->id,
                'kind' => 'work', 'started_at' => self::ts($at), 'source' => 'auto']);
            $this->event($wo, $user, $from, 'in_progress', null, $at, $clientEventId);

            return $wo;
        });
    }

    /** 5.8: إيقاف مؤقت بسبب من القائمة، ويتوقف الوقت لكل الفريق (N-13 للمنسق) */
    public function hold(WorkOrder $wo, User $user, string $reason, CarbonInterface $at, ?string $clientEventId = null, ?string $note = null, bool $announce = true): WorkOrder
    {
        if (! in_array($reason, self::HOLD_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => [__('validation.in', ['attribute' => 'reason'])]]);
        }
        $wo = DB::transaction(function () use ($wo, $user, $reason, $at, $clientEventId, $note) {
            $wo = $this->lockAssigned($wo, $user);
            if ($wo->status !== 'in_progress') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $wo->status, 'to' => 'on_hold']);
            }
            $wo->update(['status' => 'on_hold', 'on_hold_reason' => $reason]);
            self::closeEntries($wo->id, $at);
            $this->event($wo, $user, 'in_progress', 'on_hold', $note ? "{$reason}: {$note}" : $reason, $at, $clientEventId);

            return $wo;
        });
        if ($announce) {
            ServiceEvent::dispatch(ServiceEvent::WO_ON_HOLD, $wo->serviceRequest, $user, $reason, $wo);
        }

        return $wo;
    }

    /** صورة بمرحلتها (FR-TEC-04). أمر أُعيد إسناده يقبلها مع علامة "من فني سابق" (04 §6.4). */
    public function addPhoto(WorkOrder $wo, User $user, string $attachmentId, string $phase, ?string $caption, CarbonInterface $at): void
    {
        if (! in_array($phase, self::PHASES, true)) {
            throw ValidationException::withMessages(['phase' => [__('validation.in', ['attribute' => 'phase'])]]);
        }
        $this->everAssigned($wo, $user);
        $this->ownUpload($attachmentId, $user);
        DB::table('work_order_photos')->insertOrIgnore([
            'work_order_id' => $wo->id, 'attachment_id' => $attachmentId, 'phase' => $phase, 'caption' => $caption,
            'taken_at' => self::ts($at), 'taken_by' => $user->id, 'from_previous_technician' => ! $this->isAssigned($wo, $user),
        ]);
    }

    /** ملاحظة نصية أو صوتية داخلية */
    public function addNote(WorkOrder $wo, User $user, ?string $body, ?string $voiceId, CarbonInterface $at, ?string $id = null): void
    {
        if (blank($body) && ! $voiceId) {
            throw ValidationException::withMessages(['body' => [__('validation.required', ['attribute' => 'body'])]]);
        }
        $this->everAssigned($wo, $user);
        if ($voiceId) {
            $this->ownUpload($voiceId, $user);
        }
        DB::table('work_order_notes')->insertOrIgnore([
            'id' => $id ?? (string) Str::uuid7(), 'work_order_id' => $wo->id, 'author_id' => $user->id, 'body' => $body, 'voice_id' => $voiceId,
            'is_internal' => true, 'from_previous_technician' => ! $this->isAssigned($wo, $user), 'created_at' => self::ts($at),
        ]);
    }

    /**
     * FR-WO-04: قطعة من مخزن فرع الورشة تُخصم فوراً بسعرها الحالي نسخةً للفوترة، أو قطعة من العميل بالوصف دون سعر ولا مخزون.
     *
     * @param  array{id: string, part_id?: string|null, description?: string|null, quantity: string, source: string}  $p
     */
    public function usePart(WorkOrder $wo, User $user, array $p, CarbonInterface $at): void
    {
        DB::transaction(function () use ($wo, $user, $p, $at) {
            $wo = $this->lockAssigned($wo, $user);
            if (! in_array($wo->status, ['in_progress', 'on_hold'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $wo->status]);
            }
            if (DB::table('work_order_parts')->where('id', $p['id'])->exists()) {
                return; // نفس السطر من الجهاز
            }
            $qty = (string) $p['quantity'];
            if (bccomp($qty, '0', 2) <= 0) {
                throw ValidationException::withMessages(['quantity' => [__('validation.gt.numeric', ['attribute' => 'quantity', 'value' => 0])]]);
            }
            $row = ['id' => $p['id'], 'work_order_id' => $wo->id, 'quantity' => $qty, 'source' => $p['source'], 'recorded_by' => $user->id, 'recorded_at' => self::ts($at)];
            if ($p['source'] === 'store') {
                $part = Part::query()->find($p['part_id'] ?? null);
                if (! $part) {
                    throw ValidationException::withMessages(['part_id' => [__('validation.exists', ['attribute' => 'part'])]]);
                }
                $location = $this->stock->locationFor($part->id, $wo->branch_id, $qty);
                if (! $location) {
                    throw new ApiProblem('STOCK_INSUFFICIENT', 422, null, ['part_id' => $part->id]);
                }
                $this->stock->move($part->id, $location, '-'.$qty, 'work_order', $user, $wo->number, ['work_order_id' => $wo->id]);
                $row += ['part_id' => $part->id, 'stock_location_id' => $location, 'unit_price' => $part->price, 'description' => null];
            } else {
                if (blank($p['description'] ?? null)) {
                    throw ValidationException::withMessages(['description' => [__('validation.required', ['attribute' => 'description'])]]);
                }
                $row += ['part_id' => null, 'stock_location_id' => null, 'unit_price' => null, 'description' => $p['description']];
            }
            DB::table('work_order_parts')->insert($row);
            Audit::record('work_order.part_used', 'work_order', $wo->id, null, ['line' => $p['id'], 'source' => $p['source'], 'quantity' => $qty], $user);
        });
    }

    /** part_removed: حذف سطر مسجل، والقطعة من المخزن تعود له */
    public function removePart(WorkOrder $wo, User $user, string $lineId, CarbonInterface $at): void
    {
        DB::transaction(function () use ($wo, $user, $lineId, $at) {
            $wo = $this->lockAssigned($wo, $user);
            $line = DB::table('work_order_parts')->where('id', $lineId)->where('work_order_id', $wo->id)->lockForUpdate()->first();
            if (! $line || $line->removed_at) {
                return;
            }
            if (! in_array($wo->status, ['in_progress', 'on_hold'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $wo->status]);
            }
            if ($line->source === 'store') {
                $this->stock->move($line->part_id, $line->stock_location_id, (string) $line->quantity, 'work_order', $user, $wo->number, ['work_order_id' => $wo->id]);
            }
            DB::table('work_order_parts')->where('id', $lineId)->update(['removed_at' => self::ts($at), 'removed_by' => $user->id]);
        });
    }

    /** قراءة العداد مع صورته (FR-TEC-04) تدخل سجل المعدة، والأقل من السابقة تُعلَّم للمراجعة */
    public function recordMeter(WorkOrder $wo, User $user, int $reading, ?string $photoId, CarbonInterface $at): void
    {
        DB::transaction(function () use ($wo, $user, $reading, $photoId, $at) {
            $wo = $this->lockAssigned($wo, $user);
            $sr = ServiceRequest::query()->findOrFail($wo->service_request_id);
            if (! $sr->customer_equipment_id) {
                throw new ApiProblem('NO_HOUR_METER', 422); // المكوّن المنفصل بلا عداد (BR-07)
            }
            if ($photoId) {
                $this->ownUpload($photoId, $user);
                DB::table('work_order_photos')->insertOrIgnore(['work_order_id' => $wo->id, 'attachment_id' => $photoId, 'phase' => 'hour_meter',
                    'caption' => (string) $reading, 'taken_at' => self::ts($at), 'taken_by' => $user->id, 'from_previous_technician' => false]);
            }
            $e = CustomerEquipment::query()->lockForUpdate()->findOrFail($sr->customer_equipment_id);
            $lower = $e->current_hour_meter !== null && $reading < $e->current_hour_meter;
            $e->readings()->create(['reading' => $reading, 'source' => 'technician', 'work_order_id' => $wo->id, 'photo_id' => $photoId,
                'recorded_by' => $user->id, 'recorded_at' => $at, 'needs_review' => $lower, 'note' => $lower ? __('service.tech_lower_reading') : null]);
            if (! $lower) {
                $e->update(['current_hour_meter' => $reading, 'hour_meter_updated_at' => $at]);
            }
        });
    }

    /**
     * إغلاق فترات العمل المفتوحة عند وقت الحدث. الفترة التي بدأت بعده (فتحها الخادم عند الإعادة 5.11، ووقت الجهاز أسبق) فارغة فتُحذف.
     */
    public static function closeEntries(string $workOrderId, CarbonInterface $at): void
    {
        $ts = self::ts($at);
        DB::table('time_entries')->where('work_order_id', $workOrderId)->whereNull('ended_at')->where('started_at', '>=', $ts)->delete();
        DB::table('time_entries')->where('work_order_id', $workOrderId)->whereNull('ended_at')->update(['ended_at' => $ts]);
    }

    /** وقت العمل بالثواني من فترات الحالة (المفتوحة حتى الآن) */
    public static function workedSeconds(string $workOrderId, ?string $technicianId = null): int
    {
        return (int) DB::table('time_entries')->where('work_order_id', $workOrderId)
            ->when($technicianId, fn ($q) => $q->where('technician_user_id', $technicianId))
            ->selectRaw('COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, ?))), 0) AS s', [self::ts(now())])->value('s');
    }

    public function lockAssigned(WorkOrder $wo, User $user): WorkOrder
    {
        $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
        if (! $this->isAssigned($wo, $user)) {
            throw new ApiProblem('NOT_ASSIGNED', 409); // أُعيد إسناده أو أُلغي (04 §6.4)
        }

        return $wo;
    }

    /** الصور والملاحظات تُقبل ممن أُسند إليه الأمر يوماً، ولو أُعيد إسناده بعدها */
    private function everAssigned(WorkOrder $wo, User $user): void
    {
        if (! DB::table('work_order_assignments')->where('work_order_id', $wo->id)->where('technician_user_id', $user->id)->exists()) {
            throw new ApiProblem('NOT_ASSIGNED', 409);
        }
    }

    public function ownUpload(string $attachmentId, User $user): void
    {
        if (! DB::table('attachments')->where('id', $attachmentId)->where('uploaded_by', $user->id)->exists()) {
            throw ValidationException::withMessages(['attachment_id' => [__('validation.exists', ['attribute' => 'attachment'])]]);
        }
    }

    public function event(WorkOrder $wo, User $actor, string $from, string $to, ?string $reason, CarbonInterface $at, ?string $clientEventId): void
    {
        DB::table('work_order_status_events')->insert([
            'id' => (string) Str::uuid7(), 'work_order_id' => $wo->id, 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor->id,
            'reason' => $reason, 'occurred_at' => self::ts($at), 'received_at' => self::ts(now()), 'client_event_id' => $clientEventId,
        ]);
        Audit::record("work_order.{$to}", 'work_order', $wo->id, ['status' => $from], ['status' => $to, 'reason' => $reason], $actor);
    }

    /** datetime(6) بالميكروثانية: منشئ الاستعلام يحذفها من Carbon */
    public static function ts(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s.u');
    }
}
