<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Services\Uploads;
use App\Modules\Platform\Support\Audit;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Pdf\ServicePdf;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * التسليم للعميل (FR-SRV-09، BR-20، US-49، SM-4 4.13): بعد الإصلاح، أو بعد إلغاء أو رفض والمعدة في الورشة.
 * الفاتورة تصدر والدفعة بالمتبقي تُسجل في معاملة التسليم نفسها، والتوقيع والاسم إلزاميان، ثم إيصال PDF.
 */
final class HandoverService
{
    public function __construct(private readonly InvoiceService $invoices, private readonly Uploads $uploads, private readonly ServicePdf $pdf) {}

    /** A-22 "جاهز للاستلام": المكتملة، والملغاة أو المرفوضة المستلمة في الورشة، بلا تسليم بعد */
    public function readyQuery()
    {
        return ServiceRequest::query()->whereIn('status', ['completed', 'cancelled', 'declined'])
            ->whereHas('intake')
            ->whereNotIn('id', DB::table('service_handovers')->select('service_request_id'));
    }

    /** الفحص اكتمل (أمر فحص منجز أو معتمد أو مغلق): رسوم الفحص عند الإلغاء (BR-11) */
    public static function inspectionDone(ServiceRequest $sr): bool
    {
        return WorkOrder::query()->where('service_request_id', $sr->id)->where('type', 'inspection')
            ->whereIn('status', ['work_completed', 'verified', 'closed'])->exists();
    }

    /**
     * المبلغ المستحق قبل التسليم: رصيد فاتورة الطلب (أو إجمالي مسودتها)، أو رسوم الفحص المتوقعة للملغى بعد فحص.
     *
     * @return array{invoice: Invoice|null, due: string, fee_pending: bool}
     */
    public function due(ServiceRequest $sr): array
    {
        $invoice = Invoice::query()->where('service_request_id', $sr->id)->where('status', '!=', 'void')->first();
        if ($invoice) {
            return ['invoice' => $invoice, 'due' => $invoice->status === 'draft' ? (string) $invoice->total : $invoice->balance(), 'fee_pending' => false];
        }
        if ($sr->status !== 'completed' && self::inspectionDone($sr) && bccomp((string) ($sr->inspection_fee ?? '0'), '0', 3) > 0) {
            $rate = (string) Setting::read('vat.default_rate', '5.00');
            $fee = (string) $sr->inspection_fee;

            return ['invoice' => null, 'due' => bcadd($fee, bcdiv(bcmul($fee, $rate, 6), '100', 3), 3), 'fee_pending' => true];
        }

        return ['invoice' => null, 'due' => '0.000', 'fee_pending' => false];
    }

    /**
     * @param  array{receiver_name: string, receiver_phone?: string|null, signature_id: string,
     *               payment?: array{method: string, amount: string, reference_text?: string|null}|null}  $data
     */
    public function handOver(ServiceRequest $sr, User $staff, array $data): object
    {
        $row = DB::transaction(function () use ($sr, $staff, $data) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
            if (! in_array($sr->status, ['completed', 'cancelled', 'declined'], true) || ! $sr->intake()->exists()) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $sr->status, 'to' => 'handed_over']);
            }
            if (DB::table('service_handovers')->where('service_request_id', $sr->id)->exists()) {
                throw new ApiProblem('ALREADY_HANDED_OVER', 409);
            }
            if (! DB::table('attachments')->where('id', $data['signature_id'])->where('uploaded_by', $staff->id)->exists()) {
                throw ValidationException::withMessages(['signature_id' => [__('validation.exists', ['attribute' => 'signature'])]]);
            }
            // الفاتورة: فاتورة الطلب، أو رسوم الفحص للملغى بعد فحص. المسودة تصدر لحظة التسليم (BR-18 بالقياس)
            $invoice = Invoice::query()->where('service_request_id', $sr->id)->where('status', '!=', 'void')->lockForUpdate()->first();
            if (! $invoice && $sr->status !== 'completed' && self::inspectionDone($sr)) {
                $invoice = $this->invoices->draftForService($sr, [], true);
            }
            if ($invoice && $invoice->status === 'draft') {
                $invoice = $this->invoices->issue($invoice, $staff);
            }
            $payment = null;
            $p = $data['payment'] ?? null;
            if ($invoice && in_array($invoice->status, Invoice::UNPAID, true) && ($p || (bool) Setting::read('service.release_requires_payment', true))) {
                if (! $p) {
                    throw new ApiProblem('PAYMENT_REQUIRED', 409, null, ['due' => $invoice->balance()]); // BR-20
                }
                if (bccomp((string) $p['amount'], $invoice->balance(), 3) !== 0) {
                    throw ValidationException::withMessages(['payment.amount' => [__('service.handover_amount', ['due' => $invoice->balance()])]]);
                }
                $payment = $this->invoices->recordPayment($invoice, $staff, $p);
            }
            $id = (string) Str::uuid7();
            DB::table('service_handovers')->insert([
                'id' => $id, 'service_request_id' => $sr->id, 'handed_over_at' => ExecutionService::ts(now()), 'handed_over_by' => $staff->id,
                'receiver_name' => $data['receiver_name'], 'receiver_phone' => $data['receiver_phone'] ?? null, 'signature_id' => $data['signature_id'],
                'invoice_id' => $invoice?->id, 'payment_id' => $payment?->id,
            ]);
            if ($sr->status === 'completed') {
                $sr->update(['status' => 'closed', 'closed_at' => now()]); // 4.13
                Audit::record('service.closed', 'service_request', $sr->id, ['status' => 'completed'], ['status' => 'closed'], $staff);
            }
            $handover = DB::table('service_handovers')->where('id', $id)->first();
            $pdf = $this->uploads->storeGenerated($this->pdf->handover($sr->fresh(['customer', 'organization', 'equipment.equipmentModel.brand', 'intake']), $handover,
                $invoice?->fresh(), $payment instanceof Payment ? $payment : null, $staff), 'application/pdf', "{$sr->number}-handover.pdf", $staff);
            DB::table('service_handovers')->where('id', $id)->update(['receipt_pdf_id' => $pdf->id]);
            Audit::record('service.handed_over', 'service_request', $sr->id, null, ['handover' => $id, 'invoice' => $invoice?->number, 'payment' => $payment?->id], $staff);

            return [$sr, DB::table('service_handovers')->where('id', $id)->first()];
        });
        ServiceEvent::dispatch(ServiceEvent::HANDED_OVER, $row[0], $staff);

        return $row[1];
    }

    public static function handoverOf(string $srId): ?object
    {
        return DB::table('service_handovers')->where('service_request_id', $srId)->first();
    }
}
