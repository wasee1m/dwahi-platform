<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Refund;
use App\Modules\Billing\Pdf\InvoicePdf;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Services\Uploads;
use App\Modules\Platform\Support\Audit;
use App\Modules\Platform\Support\SettingsRegistry;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Support\QuoteMath;
use App\Support\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * الفواتير والدفعات والاستردادات (SM-6، FR-PAY-02، FR-PAY-03، FR-PAY-06). كل عملية في معاملة بقفل صف
 * الفاتورة أو الدفعة، والرقم المتصل (BR-14) يُسند داخل معاملة الإصدار نفسها فلا فجوة عند الفشل.
 */
final class InvoiceService
{
    public function __construct(private readonly Uploads $uploads, private readonly InvoicePdf $pdf) {}

    /** 6.1 من طلب قطع: البنود والضريبة كما في الطلب، ورسوم التوصيل سطر مستقل (BR-01) */
    public function draftFromOrder(SalesOrder $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            $existing = Invoice::query()->where('sales_order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $order->loadMissing(['items', 'customer', 'organization']);
            $lines = $order->items->map(fn ($i) => [
                'item_type' => 'part', 'order_item_id' => $i->id,
                'description_ar' => "{$i->part_number_snapshot} · {$i->name_ar_snapshot}",
                'description_en' => "{$i->part_number_snapshot} · {$i->name_en_snapshot}",
                'quantity' => $i->quantity, 'unit' => $i->unit, 'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount, 'vat_rate' => $i->vat_rate,
                'line_subtotal' => $i->line_subtotal, 'line_vat' => $i->line_vat, 'line_total' => $i->line_total,
                'warranty_months' => $i->warranty_months,
            ])->all();
            if (bccomp((string) $order->delivery_fee, '0', 3) > 0) {
                $rate = (string) Setting::read('vat.default_rate', '5.00');
                $lines[] = [
                    'item_type' => 'delivery', 'order_item_id' => null,
                    'description_ar' => __('billing.delivery_line', ['governorate' => __("accounts.governorates.{$order->delivery_governorate}", [], 'ar')], 'ar'),
                    'description_en' => __('billing.delivery_line', ['governorate' => __("accounts.governorates.{$order->delivery_governorate}", [], 'en')], 'en'),
                    'quantity' => '1', 'unit' => 'service', 'unit_price' => $order->delivery_fee, 'discount_amount' => '0',
                    'vat_rate' => $rate, 'line_subtotal' => $order->delivery_fee, 'line_vat' => $order->delivery_vat,
                    'line_total' => bcadd((string) $order->delivery_fee, (string) $order->delivery_vat, 3), 'warranty_months' => null,
                ];
            }

            return $this->createDraft(['sales_order_id' => $order->id, 'branch_id' => $order->branch_id], $order->customer, $order->organization, $lines);
        });
    }

    /** 6.1 من عرض معدة مقبول: كل بنود العرض بخصوماتها، والمحاسب يصدرها من A-13 */
    public function draftFromQuotation(Quotation $q): Invoice
    {
        return DB::transaction(function () use ($q) {
            $existing = Invoice::query()->where('quotation_id', $q->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $q->loadMissing(['items', 'rfq.customer', 'rfq.organization']);
            $lines = $q->items->map(fn ($i) => [
                'item_type' => $i->item_type, 'order_item_id' => null,
                'description_ar' => $i->description_ar, 'description_en' => $i->description_en,
                'quantity' => $i->quantity, 'unit' => $i->unit, 'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount, 'vat_rate' => $i->vat_rate,
                'line_subtotal' => $i->line_subtotal, 'line_vat' => $i->line_vat, 'line_total' => $i->line_total,
                'warranty_months' => $i->warranty_months,
            ])->all();

            return $this->createDraft(['quotation_id' => $q->id, 'branch_id' => $q->rfq->branch_id], $q->rfq->customer, $q->rfq->organization, $lines);
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function createDraft(array $source, User $customer, ?object $org, array $lines): Invoice
    {
        $totals = QuoteMath::totals($lines);
        $invoice = Invoice::query()->create($source + [
            'customer_user_id' => $customer->id,
            'organization_id' => $org?->id,
            'status' => 'draft',
            'seller_snapshot' => $this->seller(),
            'buyer_snapshot' => $this->buyer($customer, $org),
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'vat_total' => $totals['vat_total'],
            'total' => $totals['total'],
        ]);
        foreach (array_values($lines) as $n => $line) {
            $invoice->items()->create(['line_no' => $n + 1] + $line);
        }

        return $invoice;
    }

    /**
     * 6.2: الإصدار برقم INV متصل و PDF ثابت. الاستحقاق افتراضياً يوم الإصدار (الدفع عند الاستلام)،
     * ويحدده المحاسب لعرض المعدة. تواريخ الضمان من تاريخ الإصدار (FR-CAT-07).
     */
    public function issue(Invoice $invoice, User $by, ?string $dueDate = null): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $by, $dueDate) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'draft') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $invoice->status, 'to' => 'issued']);
            }
            $today = now('Asia/Muscat')->startOfDay();
            $due = $dueDate ? Carbon::parse($dueDate, 'Asia/Muscat')->startOfDay() : $today;
            if ($due->lt($today)) {
                throw ValidationException::withMessages(['due_date' => [__('billing.due_in_past')]]);
            }
            foreach ($invoice->items()->whereNotNull('warranty_months')->get() as $item) {
                $item->update(['warranty_until' => $today->copy()->addMonths($item->warranty_months)->toDateString()]);
            }
            $number = DocumentNumber::next('INV');
            $invoice->update([
                'number' => $number, 'status' => 'issued', 'issue_date' => $today->toDateString(), 'due_date' => $due->toDateString(),
                'seller_snapshot' => $this->seller(), 'issued_by' => $by->id, 'issued_at' => now(),
            ]);
            // دفعات الطلب المسجلة قبل الفاتورة (الدفع عند التسليم في نفس المعاملة) تُربط بها
            if ($invoice->sales_order_id) {
                Payment::query()->where('sales_order_id', $invoice->sales_order_id)->whereNull('invoice_id')->update(['invoice_id' => $invoice->id]);
            }
            $this->recompute($invoice);
            $invoice->refresh()->load(['items', 'payments']);
            $pdf = $this->uploads->storeGenerated($this->pdf->invoice($invoice), 'application/pdf', "{$number}.pdf", $by);
            $invoice->update(['pdf_id' => $pdf->id]);
            Audit::record('invoice.issued', 'invoice', $invoice->id, ['status' => 'draft'], ['number' => $number, 'total' => $invoice->total], $by);

            return $invoice;
        });
        InvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /** 6.5: إلغاء فاتورة صادرة بلا دفعات، بإشعار دائن بالقيمة الكاملة وسبب إلزامي */
    public function void(Invoice $invoice, User $by, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $by, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued' || Payment::query()->where('invoice_id', $invoice->id)->whereIn('status', Payment::COLLECTED)->exists()) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $invoice->status, 'to' => 'void']);
            }
            $this->creditNote($invoice, $by, $reason, (string) $invoice->subtotal, (string) $invoice->vat_total, (string) $invoice->total);
            $before = $invoice->status;
            $invoice->update(['status' => 'void', 'void_reason' => $reason]);
            Audit::record('invoice.voided', 'invoice', $invoice->id, ['status' => $before], ['status' => 'void', 'reason' => $reason], $by);

            return $invoice->fresh();
        });
    }

    /**
     * 6b.4: دفعة يدوية من المحاسب على فاتورة صادرة (US-38). الجزئية ممكنة، ولا تتجاوز المتبقي.
     *
     * @param  array{method: string, amount: string, reference_text?: string|null, proof_id?: string|null, received_at?: string|null}  $data
     */
    public function recordPayment(Invoice $invoice, User $by, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $by, $data) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, Invoice::UNPAID, true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $invoice->status]);
            }
            if (bccomp($data['amount'], $invoice->balance(), 3) > 0) {
                throw ValidationException::withMessages(['amount' => [__('billing.amount_over_balance', ['balance' => $invoice->balance()])]]);
            }
            $payment = $this->payment(['invoice_id' => $invoice->id, 'sales_order_id' => $invoice->sales_order_id, 'branch_id' => $invoice->branch_id], $by, $data);
            $this->recompute($invoice);
            Audit::record('payment.recorded', 'invoice', $invoice->id, null, ['payment_id' => $payment->id, 'amount' => $payment->amount, 'method' => $payment->method], $by);

            return $payment;
        });
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $data */
    public function payment(array $target, User $by, array $data): Payment
    {
        return Payment::query()->create($target + [
            'method' => $data['method'],
            'status' => 'succeeded',
            'amount' => $data['amount'],
            'idempotency_key' => (string) Str::uuid7(),
            'reference_text' => $data['reference_text'] ?? null,
            'proof_id' => $data['proof_id'] ?? null,
            'recorded_by' => $by->id,
            'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
        ]);
    }

    /**
     * 6b.5: استرداد يدوي (نقد أو تحويل) مع إشعار دائن. مجموع الاستردادات لا يتجاوز الدفعة (قفل صف الدفعة).
     * الضريبة في الإشعار من بنود المرتجع إن وُجدت، وإلا بنسبة ضريبة الفاتورة.
     *
     * @param  array{amount: string, method: string, reason: string, reference_text?: string|null, return_request_id?: string|null, subtotal?: string, vat_total?: string}  $data
     */
    public function refund(Payment $payment, User $by, array $data): Refund
    {
        return DB::transaction(function () use ($payment, $by, $data) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $payment->status]);
            }
            $amount = $data['amount'];
            if (bccomp($amount, $payment->refundable(), 3) > 0) {
                throw ValidationException::withMessages(['amount' => [__('billing.refund_over_payment', ['max' => $payment->refundable()])]]);
            }
            $invoice = $payment->invoice_id ? Invoice::query()->lockForUpdate()->find($payment->invoice_id) : null;
            if (! $invoice || $invoice->status === 'draft') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['reason' => 'no_issued_invoice']);
            }
            if (isset($data['subtotal'], $data['vat_total'])) {
                [$sub, $vat] = [$data['subtotal'], $data['vat_total']];
            } else {
                $vat = bccomp((string) $invoice->total, '0', 3) > 0
                    ? QuoteMath::round(bcdiv(bcmul($amount, (string) $invoice->vat_total, 6), (string) $invoice->total, 6), 3) : '0.000';
                $sub = bcsub($amount, $vat, 3);
            }
            $note = $this->creditNote($invoice, $by, $data['reason'], $sub, $vat, $amount, $data['return_request_id'] ?? null);
            $refund = Refund::query()->create([
                'payment_id' => $payment->id, 'credit_note_id' => $note->id, 'return_request_id' => $data['return_request_id'] ?? null,
                'method' => $data['method'], 'amount' => $amount, 'reason' => $data['reason'],
                'reference_text' => $data['reference_text'] ?? null, 'status' => 'succeeded', 'created_by' => $by->id,
            ]);
            $refunded = bcadd((string) $payment->refunded_amount, $amount, 3);
            $payment->update([
                'refunded_amount' => $refunded,
                'status' => bccomp($refunded, (string) $payment->amount, 3) === 0 ? 'refunded' : 'partially_refunded',
            ]);
            Audit::record('payment.refunded', 'payment', $payment->id, null, ['refund_id' => $refund->id, 'amount' => $amount, 'credit_note' => $note->number], $by);

            return $refund;
        });
    }

    private function creditNote(Invoice $invoice, User $by, string $reason, string $subtotal, string $vat, string $total, ?string $returnId = null): CreditNote
    {
        $number = DocumentNumber::next('CN');
        $note = CreditNote::query()->create([
            'number' => $number, 'invoice_id' => $invoice->id, 'return_request_id' => $returnId, 'reason' => $reason,
            'subtotal' => $subtotal, 'vat_total' => $vat, 'total' => $total, 'issued_at' => now(), 'issued_by' => $by->id,
        ]);
        $pdf = $this->uploads->storeGenerated($this->pdf->creditNote($note->load('invoice')), 'application/pdf', "{$number}.pdf", $by);
        $note->update(['pdf_id' => $pdf->id]);

        return $note;
    }

    /** المدفوع إسقاط من الدفعات المحصلة، والحالة منه (6.3، 6.4). المعدة تُعلَّم مباعة عند السداد الكامل (BR-19). */
    public function recompute(Invoice $invoice): void
    {
        $paid = (string) Payment::query()->where('invoice_id', $invoice->id)->whereIn('status', Payment::COLLECTED)->sum('amount');
        $paid = bccomp($paid, (string) $invoice->total, 3) > 0 ? (string) $invoice->total : $paid;
        $status = $invoice->status;
        if (in_array($status, ['issued', 'partially_paid', 'paid'], true)) {
            $status = match (true) {
                bccomp($paid, (string) $invoice->total, 3) >= 0 => 'paid',
                bccomp($paid, '0', 3) > 0 => 'partially_paid',
                default => 'issued',
            };
        }
        $invoice->update(['amount_paid' => $paid, 'status' => $status]);
        if ($status === 'paid' && $invoice->quotation_id) {
            $rfqId = Quotation::query()->whereKey($invoice->quotation_id)->value('rfq_id');
            EquipmentListing::query()->where('reserved_for_rfq_id', $rfqId)->where('status', 'reserved')->update(['status' => 'sold']);
        }
    }

    /** @return array<string, string> */
    private function seller(): array
    {
        return (array) Setting::read('company.profile', SettingsRegistry::all()['company.profile']['default']);
    }

    /** @return array<string, string|null> */
    private function buyer(User $customer, ?object $org): array
    {
        return [
            'name' => $org?->name ?? $customer->full_name,
            'cr_number' => $org?->cr_number,
            'vat_number' => $org?->vat_number,
            'contact' => $customer->full_name,
            'phone' => $customer->phone_e164,
            'email' => $customer->email,
        ];
    }
}
