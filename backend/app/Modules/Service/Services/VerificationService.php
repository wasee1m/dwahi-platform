<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Platform\Services\Uploads;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Services\QuotationService;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Pdf\ServicePdf;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مراجعة المنسق وإغلاق العمل (FR-WO-05، US-34، SM-5 5.11 إلى 5.13) والعمل الإضافي (SM-4 جدول 4a)،
 * واكتمال طلب الصيانة (4.12) بمسودة الفاتورة من التقديرات المعتمدة وتقرير الخدمة.
 */
final class VerificationService
{
    public function __construct(
        private readonly ExecutionService $exec,
        private readonly QuotationService $quotes,
        private readonly InvoiceService $invoices,
        private readonly Uploads $uploads,
        private readonly ServicePdf $pdf,
    ) {}

    /** 5.12 الاعتماد بتقرير PDF، ومع "بلا تكلفة" وسببها يُغلق مباشرة (5.13) */
    public function verify(WorkOrder $wo, User $staff, bool $noCharge = false, ?string $reason = null): WorkOrder
    {
        if ($noCharge && blank($reason)) {
            throw new ApiProblem('REASON_REQUIRED', 422);
        }
        $wo = DB::transaction(function () use ($wo, $staff, $noCharge, $reason) {
            $wo = $this->lock($wo, ['work_completed']);
            $to = $noCharge ? 'closed' : 'verified';
            $wo->update(['status' => $to, 'verified_by' => $staff->id, 'verified_at' => now(), 'no_charge' => $noCharge,
                'no_charge_reason' => $noCharge ? $reason : null, 'closed_at' => $noCharge ? now() : null]);
            $this->exec->event($wo, $staff, 'work_completed', $to, $noCharge ? $reason : null, now(), null);
            $wo->load(['serviceRequest.customer', 'serviceRequest.organization', 'serviceRequest.equipment.equipmentModel.brand', 'technicians', 'verifier']);
            $number = $wo->number;
            $pdf = $this->uploads->storeGenerated($this->pdf->report($wo), 'application/pdf', "{$number}-report.pdf", $staff);
            DB::table('service_reports')->where('work_order_id', $wo->id)->update(['pdf_id' => $pdf->id]);

            return $wo;
        });
        $this->completeIfDone($wo->serviceRequest, $staff);

        return $wo;
    }

    /** 5.11: إعادة للفني بملاحظة إلزامية. الوقت يُستأنف للفني الرئيسي، ويوقفه بالإيقاف المؤقت. */
    public function returnToTechnician(WorkOrder $wo, User $staff, string $note): WorkOrder
    {
        $wo = DB::transaction(function () use ($wo, $staff, $note) {
            $wo = $this->lock($wo, ['work_completed']);
            $wo->update(['status' => 'in_progress']);
            $lead = DB::table('work_order_assignments')->where('work_order_id', $wo->id)->where('role', 'lead')->whereNull('unassigned_at')->value('technician_user_id');
            if ($lead) {
                DB::table('time_entries')->insert(['id' => (string) Str::uuid7(), 'work_order_id' => $wo->id, 'technician_user_id' => $lead,
                    'kind' => 'work', 'started_at' => ExecutionService::ts(now()), 'source' => 'auto']);
            }
            $this->exec->event($wo, $staff, 'work_completed', 'in_progress', $note, now(), null);

            return $wo;
        });
        ServiceEvent::dispatch(ServiceEvent::WO_RETURNED, $wo->serviceRequest, $staff, $note, $wo);

        return $wo;
    }

    /** 4a.3: غير لازم أو خارج النطاق، بسبب إلزامي. العمل المعتمد يستمر. */
    public function rejectAdditional(string $id, User $staff, string $reason): void
    {
        $wo = DB::transaction(function () use ($id, $staff, $reason) {
            $r = DB::table('additional_work_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $r?->status]);
            }
            DB::table('additional_work_requests')->where('id', $id)->update(['status' => 'rejected', 'rejection_reason' => $reason, 'decided_at' => ExecutionService::ts(now())]);
            Audit::record('additional.rejected', 'work_order', $r->work_order_id, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason], $staff);

            return WorkOrder::query()->findOrFail($r->work_order_id);
        });
        ServiceEvent::dispatch(ServiceEvent::ADDITIONAL_DECIDED, $wo->serviceRequest, $staff, 'rejected', $wo);
        $this->completeIfDone($wo->serviceRequest, $staff);
    }

    /** 4a.2 تمهيداً: طلب عرض إضافي (is_additional) ومسودة يحررها المنسق في A-06 ثم يرسلها */
    public function estimateAdditional(string $id, User $staff): Quotation
    {
        return DB::transaction(function () use ($id, $staff) {
            $r = DB::table('additional_work_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $r?->status]);
            }
            $wo = WorkOrder::query()->findOrFail($r->work_order_id);
            $sr = ServiceRequest::query()->findOrFail($wo->service_request_id);
            $rfq = $r->rfq_id ? Rfq::query()->findOrFail($r->rfq_id) : Rfq::query()->create([
                'number' => DocumentNumber::next('RFQ'), 'type' => 'service', 'is_additional' => true,
                'customer_user_id' => $sr->customer_user_id, 'organization_id' => $sr->organization_id,
                'status' => 'in_review', 'source' => 'system', 'assigned_to' => $staff->id, 'branch_id' => $sr->branch_id,
                'service_request_id' => $sr->id, 'customer_equipment_id' => $sr->customer_equipment_id,
                'notes' => trim($r->description."\n".($r->parts_needed ?? '')), 'created_by' => $staff->id,
            ]);
            DB::table('additional_work_requests')->where('id', $id)->update(['rfq_id' => $rfq->id]);

            return $this->quotes->draft($rfq, $staff);
        });
    }

    /** ردّ العميل على تقدير إضافي (4a.2، 4a.4 إلى 4a.6). يُستدعى من ServiceRequestService::onQuote. */
    public function onAdditionalQuote(QuotingEvent $e, bool $withinPreapproval): void
    {
        $r = DB::table('additional_work_requests')->where('rfq_id', $e->rfq->id)->first();
        if (! $r) {
            return;
        }
        $to = match ($e->type) {
            QuotingEvent::QUOTATION_SENT => $withinPreapproval ? 'approved' : 'quoted',
            QuotingEvent::QUOTATION_ACCEPTED => 'approved',
            QuotingEvent::QUOTATION_REJECTED, QuotingEvent::QUOTATION_EXPIRED => 'rejected',
            QuotingEvent::REVISION_REQUESTED => 'pending',
            default => null,
        };
        if (! $to) {
            return;
        }
        $changed = DB::transaction(function () use ($r, $to, $e) {
            $row = DB::table('additional_work_requests')->where('id', $r->id)->lockForUpdate()->first();
            $from = match ($to) {
                'quoted' => ['pending'],
                'approved' => ['pending', 'quoted'],
                'rejected', 'pending' => ['quoted'],
            };
            if (! in_array($row->status, $from, true)) {
                return false;
            }
            DB::table('additional_work_requests')->where('id', $r->id)->update([
                'status' => $to, 'quotation_id' => $e->quotation?->id ?? $row->quotation_id,
                'decided_at' => in_array($to, ['approved', 'rejected'], true) ? ExecutionService::ts(now()) : null,
            ]);
            if ($to === 'approved' && $e->type === QuotingEvent::QUOTATION_SENT && $e->quotation) {
                $this->quotes->autoAccept($e->quotation); // BR-06 (4a.6)
            }

            return true;
        });
        if (! $changed) {
            return;
        }
        $wo = WorkOrder::query()->findOrFail($r->work_order_id);
        if (in_array($to, ['approved', 'rejected'], true)) {
            ServiceEvent::dispatch(ServiceEvent::ADDITIONAL_DECIDED, $wo->serviceRequest, null, $to, $wo);
            if ($to === 'approved' && $e->type === QuotingEvent::QUOTATION_SENT) {
                ServiceEvent::dispatch(ServiceEvent::AUTO_APPROVED, $wo->serviceRequest, null, (string) $e->quotation?->total);
            }
            $this->completeIfDone($wo->serviceRequest, null);
        }
    }

    /**
     * 4.12: كل الأوامر verified أو closed أو cancelled، وأمر إصلاح منها على الأقل verified أو closed (الفحص وحده يتبعه تقدير، 4.7)،
     * ولا عمل إضافي pending أو quoted. الفاتورة مسودة من التقديرات المعتمدة للأوامر غير "بلا تكلفة".
     */
    public function completeIfDone(ServiceRequest $sr, ?User $actor): void
    {
        $done = DB::transaction(function () use ($sr, $actor) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
            if ($sr->status !== 'in_service') {
                return null;
            }
            $orders = WorkOrder::query()->where('service_request_id', $sr->id)->get();
            if ($orders->contains(fn (WorkOrder $w) => ! in_array($w->status, ['verified', 'closed', 'cancelled'], true))) {
                return null;
            }
            $repairs = $orders->filter(fn (WorkOrder $w) => $w->type !== 'inspection' && in_array($w->status, ['verified', 'closed'], true));
            if ($repairs->isEmpty()) {
                return null;
            }
            if (DB::table('additional_work_requests')->whereIn('work_order_id', $orders->pluck('id'))->whereIn('status', ['pending', 'quoted'])->exists()) {
                return null;
            }
            $sr->update(['status' => 'completed', 'completed_at' => now()]);
            Audit::record('service.completed', 'service_request', $sr->id, ['status' => 'in_service'], ['status' => 'completed'], $actor);

            $charged = $repairs->reject(fn (WorkOrder $w) => $w->no_charge);
            $quoteIds = $charged->pluck('quotation_id')->filter()
                ->merge(DB::table('additional_work_requests')->whereIn('work_order_id', $charged->pluck('id'))->where('status', 'approved')->pluck('quotation_id'))
                ->filter()->unique()->values();
            if ($quoteIds->isNotEmpty()) {
                $this->invoices->draftForService($sr, Quotation::query()->with('items')->whereIn('id', $quoteIds)->where('status', 'accepted')->get());
            }

            return $sr;
        });
        if ($done) {
            ServiceEvent::dispatch(ServiceEvent::COMPLETED, $done, $actor);
        }
    }

    /** 5.13: إصدار فاتورة الطلب يغلق أوامره المعتمدة */
    public function onInvoiceIssued(Invoice $invoice): void
    {
        if (! $invoice->service_request_id) {
            return;
        }
        WorkOrder::query()->where('service_request_id', $invoice->service_request_id)->where('status', 'verified')->get()
            ->each(function (WorkOrder $w) use ($invoice) {
                $w->update(['status' => 'closed', 'closed_at' => now()]);
                DB::table('work_order_status_events')->insert([
                    'id' => (string) Str::uuid7(), 'work_order_id' => $w->id, 'from_status' => 'verified', 'to_status' => 'closed',
                    'actor_user_id' => $invoice->issued_by ?? $w->verified_by, 'reason' => $invoice->number,
                    'occurred_at' => ExecutionService::ts(now()), 'received_at' => ExecutionService::ts(now()), 'client_event_id' => null,
                ]);
            });
    }

    /** @param list<string> $from */
    private function lock(WorkOrder $wo, array $from): WorkOrder
    {
        $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
        if (! in_array($wo->status, $from, true)) {
            throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $wo->status]);
        }

        return $wo;
    }
}
