<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Support\AccountChoice;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Services\QuotationService;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Support\Sla;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * انتقالات طلب الصيانة (SM-4). التقدير عرض سعر نوع "صيانة" في وحدة العروض (FR-SRV-03)، وردّ العميل عليه
 * يحرك الطلب عبر onQuote. أوامر العمل في WorkOrderService.
 */
final class ServiceRequestService
{
    public function __construct(private readonly QuotationService $quotes, private readonly WorkOrderService $workOrders) {}

    /**
     * 4.1: الإنشاء من العميل، أو من الموظف لعميل حاضر (walk_in). معدة من الأسطول، أو إضافة سريعة لها، أو مكوّن منفصل.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $customer, User $creator, string $source, array $data): ServiceRequest
    {
        $orgId = AccountChoice::resolve($customer, $data, false);
        $fee = Setting::read('fees.inspection');

        $sr = DB::transaction(function () use ($customer, $creator, $source, $data, $orgId, $fee) {
            $equipmentId = null;
            if (! empty($data['customer_equipment_id'])) {
                $equipmentId = CustomerEquipment::query()->ownedBy($customer, $orgId)->whereNull('archived_at')->whereKey($data['customer_equipment_id'])->value('id');
                if (! $equipmentId) {
                    throw ValidationException::withMessages(['customer_equipment_id' => [__('accounts.equipment_not_in_account')]]);
                }
            } elseif (! empty($data['new_equipment'])) {
                // "معدة أخرى": تُضاف للأسطول بحقول مختصرة (US-17)
                $n = $data['new_equipment'];
                if (empty($n['model_id']) && blank($n['model_text'] ?? null)) {
                    throw ValidationException::withMessages(['new_equipment.model_text' => [__('accounts.model_required')]]);
                }
                $equipmentId = CustomerEquipment::query()->create([
                    'organization_id' => $orgId, 'owner_user_id' => $orgId ? null : $customer->id,
                    'model_id' => $n['model_id'] ?? null, 'brand_text' => $n['brand_text'] ?? null, 'model_text' => $n['model_text'] ?? null,
                    'serial_number' => $n['serial_number'] ?? null,
                ])->id;
            }
            $component = $equipmentId ? null : ($data['component_type'] ?? null);
            if (! $equipmentId && ! $component) {
                throw ValidationException::withMessages(['customer_equipment_id' => [__('service.subject_required')]]);
            }
            if (blank($data['problem_description'] ?? null) && empty($data['voice_attachment_id'])) {
                throw ValidationException::withMessages(['problem_description' => [__('service.problem_required')]]);
            }
            $urgency = $data['urgency'] ?? 'normal';

            $sr = ServiceRequest::query()->create([
                'number' => DocumentNumber::next('SR'),
                'customer_user_id' => $customer->id,
                'organization_id' => $orgId,
                'customer_equipment_id' => $equipmentId,
                'component_type' => $component,
                'component_details' => $component ? ($data['component_details'] ?? null) : null,
                'status' => 'submitted',
                'urgency' => $urgency,
                'problem_description' => $data['problem_description'] ?? null,
                'preferred_date' => $data['preferred_date'] ?? null,
                'source' => $source,
                'fees_acknowledged' => true,
                'inspection_fee' => $fee,
                'branch_id' => $data['branch_id'] ?? Branch::query()->where('is_active', true)->orderBy('code')->value('id'),
                'sla_due_at' => Sla::due($urgency, now()),
                'created_by' => $creator->id,
            ]);
            foreach ($data['attachments'] ?? [] as $a) {
                $sr->attachments()->attach($a['id'], ['kind' => $a['kind']]);
            }
            if (! empty($data['voice_attachment_id'])) {
                $sr->attachments()->attach($data['voice_attachment_id'], ['kind' => 'voice']);
            }
            Audit::record('service.created', 'service_request', $sr->id, null, ['number' => $sr->number, 'source' => $source], $creator);

            return $sr;
        });
        ServiceEvent::dispatch(ServiceEvent::SUBMITTED, $sr, $creator);

        return $sr;
    }

    /**
     * 4.2 و 4.6: الفرز بالأولوية والفرع والمسار. "فحص" ينشئ أمر عمل فحص ويصبح الطلب in_service،
     * و"تقدير مباشر" يبقيه triaged حتى إرسال التقدير (4.5).
     */
    public function triage(ServiceRequest $sr, User $staff, string $route, string $urgency, string $branchId, ?string $instructions = null): ServiceRequest
    {
        return DB::transaction(function () use ($sr, $staff, $route, $urgency, $branchId, $instructions) {
            $sr = $this->lock($sr, ['submitted']);
            $sr->update(['status' => 'triaged', 'route' => $route, 'urgency' => $urgency, 'branch_id' => $branchId,
                'coordinator_id' => $staff->id, 'triaged_at' => now()]);
            Audit::record('service.triaged', 'service_request', $sr->id, ['status' => 'submitted'], ['status' => 'triaged', 'route' => $route], $staff);
            if ($route === 'inspection') {
                $this->workOrders->create($sr, $staff, 'inspection', $instructions);
                $sr->update(['status' => 'in_service']);
            }

            return $sr->fresh();
        });
    }

    /** 4.3: تعذّر قبول الطلب بسبب إلزامي (N-25) */
    public function decline(ServiceRequest $sr, User $staff, string $reason): ServiceRequest
    {
        $sr = DB::transaction(function () use ($sr, $staff, $reason) {
            $sr = $this->lock($sr, ['submitted']);
            $sr->update(['status' => 'declined', 'decline_reason' => $reason, 'coordinator_id' => $staff->id]);
            Audit::record('service.declined', 'service_request', $sr->id, ['status' => 'submitted'], ['status' => 'declined', 'reason' => $reason], $staff);

            return $sr;
        });
        ServiceEvent::dispatch(ServiceEvent::DECLINED, $sr, $staff, $reason);

        return $sr;
    }

    /**
     * 4.4: إلغاء العميل قبل بدء أي عمل. لا يُلغى إن كان أمر عمل جارياً أو بدأ إصلاح، فيتواصل مع المنسق.
     * أوامر العمل المفتوحة تُلغى، والتقدير المفتوح يُغلق. فاتورة رسوم الفحص بعد اكتماله والتسليم مع S9.
     */
    public function cancel(ServiceRequest $sr, User $user, ?string $reason): ServiceRequest
    {
        $sr = DB::transaction(function () use ($sr, $user, $reason) {
            $sr = $this->lock($sr, ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service']);
            $orders = WorkOrder::query()->where('service_request_id', $sr->id)->lockForUpdate()->get();
            $started = $orders->contains(fn (WorkOrder $w) => in_array($w->status, ['in_progress', 'work_completed'], true))
                || DB::table('work_order_status_events')->whereIn('work_order_id', $orders->where('type', 'repair')->pluck('id'))
                    ->where('to_status', 'in_progress')->exists();
            if ($started) {
                throw new ApiProblem('SERVICE_WORK_STARTED', 409);
            }
            $text = $reason ?: __('service.cancelled_by_customer');
            foreach ($orders->whereIn('status', ['unassigned', 'scheduled', 'on_hold']) as $wo) {
                $this->workOrders->cancelInside($wo, $user, $text);
            }
            Rfq::query()->where('service_request_id', $sr->id)->whereIn('status', ['submitted', 'in_review', 'quoted'])
                ->update(['status' => 'cancelled', 'cancel_reason' => $text, 'closed_at' => now()]);
            $before = $sr->status;
            $sr->update(['status' => 'cancelled', 'cancel_reason' => $text, 'cancelled_at' => now()]);
            Audit::record('service.cancelled', 'service_request', $sr->id, ['status' => $before], ['status' => 'cancelled'], $user);

            return $sr;
        });
        ServiceEvent::dispatch(ServiceEvent::CANCELLED, $sr, $user, $reason);

        return $sr;
    }

    /**
     * التقدير (FR-SRV-03): طلب عرض نوع "صيانة" مرتبط بالطلب، ومسودة يحررها المنسق في A-06 ثم يرسلها.
     * بعد تقدير مباشر (triaged) أو بعد الفحص (in_service، 4.7).
     */
    public function estimate(ServiceRequest $sr, User $staff): Quotation
    {
        return DB::transaction(function () use ($sr, $staff) {
            $sr = $this->lock($sr, ['triaged', 'in_service']);
            $rfq = Rfq::query()->where('service_request_id', $sr->id)->where('is_additional', false)
                ->whereIn('status', ['submitted', 'in_review'])->first();
            if (! $rfq) {
                // تقدير أساسي واحد لكل طلب (مفتاح فريد في rfqs). طلب التعديل يعيده in_review فتُصدر نسخة جديدة
                if (Rfq::query()->where('service_request_id', $sr->id)->where('is_additional', false)->exists()) {
                    throw new ApiProblem('INVALID_TRANSITION', 409, null, ['reason' => 'estimate_exists']);
                }
                $rfq = Rfq::query()->create([
                    'number' => DocumentNumber::next('RFQ'),
                    'type' => 'service',
                    'customer_user_id' => $sr->customer_user_id,
                    'organization_id' => $sr->organization_id,
                    'status' => 'in_review',
                    'source' => 'system',
                    'assigned_to' => $staff->id,
                    'branch_id' => $sr->branch_id,
                    'service_request_id' => $sr->id,
                    'customer_equipment_id' => $sr->customer_equipment_id,
                    'notes' => $sr->problem_description,
                    'created_by' => $staff->id,
                ]);
            }

            return $this->quotes->draft($rfq, $staff);
        });
    }

    /**
     * ردّ العميل على التقدير الأساسي (4.5، 4.7 إلى 4.10، 4.14). الإرسال ينقل الطلب إلى awaiting_approval،
     * ثم يُعتمد آلياً إن لم يتجاوز حد الموافقة المسبقة للشركة (BR-06، N-24).
     */
    public function onQuote(QuotingEvent $e): void
    {
        $rfq = $e->rfq;
        if ($rfq->type !== 'service' || $rfq->is_additional || ! $rfq->service_request_id) {
            return;
        }
        $to = match ($e->type) {
            QuotingEvent::QUOTATION_SENT => 'awaiting_approval',
            QuotingEvent::QUOTATION_ACCEPTED => 'approved',
            QuotingEvent::QUOTATION_REJECTED, QuotingEvent::QUOTATION_EXPIRED => 'cancelled',
            QuotingEvent::REVISION_REQUESTED => 'triaged',
            default => null,
        };
        if (! $to) {
            return;
        }
        $auto = false;
        $sr = DB::transaction(function () use ($rfq, $to, $e, &$auto) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($rfq->service_request_id);
            $from = match ($to) {
                'awaiting_approval' => ['triaged', 'in_service'],
                default => ['awaiting_approval'],
            };
            if (! in_array($sr->status, $from, true)) {
                return null;
            }
            $before = $sr->status;
            $extra = $to === 'cancelled' ? ['cancel_reason' => __('service.estimate_'.($e->type === QuotingEvent::QUOTATION_EXPIRED ? 'expired' : 'rejected')), 'cancelled_at' => now()] : [];
            $sr->update(['status' => $to] + $extra);
            Audit::record('service.'.$to, 'service_request', $sr->id, ['status' => $before], ['status' => $to, 'quotation' => $e->quotation?->number], $e->actor);

            if ($to === 'awaiting_approval' && $e->quotation && $this->withinPreapproval($sr, $e->quotation)) {
                $this->quotes->autoAccept($e->quotation);
                $sr->update(['status' => 'approved']);
                Audit::record('service.auto_approved', 'service_request', $sr->id, ['status' => 'awaiting_approval'], ['status' => 'approved'], null);
                $auto = true;
            }

            return $sr;
        });
        if ($sr && $auto) {
            ServiceEvent::dispatch(ServiceEvent::AUTO_APPROVED, $sr, null, (string) $e->quotation?->total);
            QuotingEvent::dispatch(QuotingEvent::QUOTATION_ACCEPTED, $rfq->fresh(), $e->quotation?->fresh());
        }
    }

    /** BR-06: للشركة فقط، وحد أكبر من صفر، وإجمالي التقدير لا يتجاوزه */
    private function withinPreapproval(ServiceRequest $sr, Quotation $q): bool
    {
        if (! $sr->organization_id) {
            return false;
        }
        $limit = (string) Organization::query()->whereKey($sr->organization_id)->value('preapproval_limit');

        return bccomp($limit, '0', 3) > 0 && bccomp((string) $q->total, $limit, 3) <= 0;
    }

    /** @param list<string> $from */
    private function lock(ServiceRequest $sr, array $from): ServiceRequest
    {
        $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
        if (! in_array($sr->status, $from, true)) {
            throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $sr->status]);
        }

        return $sr;
    }
}
