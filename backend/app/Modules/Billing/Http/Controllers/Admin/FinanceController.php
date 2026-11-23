<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Billing\Http\InvoicePresenter;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Refund;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Orders\Models\ReturnRequest;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Platform\Models\Branch;
use App\Support\OffsetCursor;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A-13 المالية (FR-PAY-02، FR-PAY-03، FR-PAY-06، US-38): الفواتير بمسوداتها، والدفعات اليدوية،
 * وتقرير التحصيل اليومي، والاستردادات، والإشعارات الدائنة. للمحاسب والمدير.
 */
class FinanceController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function invoices(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...Invoice::STATUSES, 'overdue', 'unpaid'])],
            'branch_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $today = now('Asia/Muscat')->toDateString();
        $base = Invoice::query()
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when(trim((string) ($f['q'] ?? '')), function ($q, $text) {
                $phone = Search::phone($text);
                $q->where(fn ($w) => $w->where('number', 'like', Search::contains($text))
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', Search::contains($text))
                        ->when($phone, fn ($c2) => $c2->orWhere('phone_e164', 'like', $phone)))
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', Search::contains($text)))
                    ->orWhereHas('salesOrder', fn ($o) => $o->where('number', 'like', Search::contains($text))));
            });
        $counts = [
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'unpaid' => (clone $base)->whereIn('status', Invoice::UNPAID)->count(),
            'overdue' => (clone $base)->whereIn('status', Invoice::UNPAID)->where('due_date', '<', $today)->count(),
        ];
        $status = $f['status'] ?? null;
        $rows = (clone $base)
            ->when($status === 'overdue', fn ($q) => $q->whereIn('status', Invoice::UNPAID)->where('due_date', '<', $today))
            ->when($status === 'unpaid', fn ($q) => $q->whereIn('status', Invoice::UNPAID))
            ->when($status && ! in_array($status, ['overdue', 'unpaid'], true), fn ($q) => $q->where('status', $status))
            ->with(InvoicePresenter::WITH)->orderByRaw("status = 'draft' DESC")->orderByDesc('issue_date')->orderByDesc('created_at')
            ->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (Invoice $i) => InvoicePresenter::row($i, true))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => InvoicePresenter::detail($this->invoice($id))]);
    }

    /** 6.2: إصدار مسودة (عرض معدة مقبول) بتاريخ استحقاق يحدده المحاسب */
    public function issue(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['due_date' => ['nullable', 'date_format:Y-m-d']]);
        $this->invoices->issue($this->invoice($id), $this->staff($request), $data['due_date'] ?? null);

        return $this->show($id);
    }

    /** 6.5: إلغاء فاتورة صادرة بلا دفعات، بإشعار دائن */
    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->invoices->void($this->invoice($id), $this->staff($request), $data['reason']);

        return $this->show($id);
    }

    /** 6b.4: دفعة يدوية (تحويل، شيك، نقد، نقاط بيع) بمرجع وإيصال، والجزئية ممكنة (US-38) */
    public function recordPayment(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $data = $request->validate([
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'reference_text' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['bank_transfer', 'cheque'], true)), 'nullable', 'string', 'max:255'],
            'proof_id' => ['nullable', 'uuid', Rule::exists('attachments', 'id')->where('uploaded_by', $staff->id)],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $data['amount'] = (string) $data['amount'];
        $this->invoices->recordPayment($this->invoice($id), $staff, $data);

        return $this->show($id);
    }

    public function pdf(string $id): StreamedResponse
    {
        $invoice = $this->invoice($id);
        abort_unless($invoice->pdf, 404);

        return Storage::disk('local')->download($invoice->pdf->storage_key, "{$invoice->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** الدفعات بفلترة التاريخ والفرع والطريقة */
    public function payments(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'uuid'], 'method' => ['nullable', Rule::in(Payment::MANUAL_METHODS)],
            'refundable' => ['nullable', 'boolean'],
        ]);
        [$from, $to] = $this->range($f['from'] ?? null, $f['to'] ?? null);
        $rows = Payment::query()->whereBetween('received_at', [$from, $to])
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($f['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($f['refundable'] ?? false, fn ($q) => $q->whereIn('status', ['succeeded', 'partially_refunded']))
            ->with(['recorder', 'branch', 'invoice', 'salesOrder', 'refunds'])->orderByDesc('received_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Payment $p) => InvoicePresenter::payment($p))->values()]);
    }

    /**
     * تقرير التحصيل اليومي (US-38): لكل فرع، مجمّع بالطريقة والموظف، ليطابقه المحاسب مع الصندوق وجهاز نقاط البيع.
     * الاستردادات في نفس اليوم تظهر مستقلة ولا تُخصم من التحصيل.
     */
    public function collection(Request $request): JsonResponse
    {
        $f = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'branch_id' => ['nullable', 'uuid']]);
        $date = $f['date'] ?? now('Asia/Muscat')->toDateString();
        [$from, $to] = $this->range($date, $date);

        $rows = DB::table('payments')
            ->leftJoin('users', 'users.id', '=', 'payments.recorded_by')
            ->whereBetween('payments.received_at', [$from, $to])->whereIn('payments.status', Payment::COLLECTED)
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('payments.branch_id', $b))
            ->groupBy('payments.branch_id', 'payments.method', 'payments.recorded_by', 'users.full_name')
            ->selectRaw('payments.branch_id, payments.method, payments.recorded_by, users.full_name AS staff, COUNT(*) AS n, SUM(payments.amount) AS total')
            ->get();
        $refunds = DB::table('refunds')->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->whereBetween('refunds.created_at', [$from, $to])->where('refunds.status', 'succeeded')
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('payments.branch_id', $b))
            ->groupBy('payments.branch_id', 'refunds.method')
            ->selectRaw('payments.branch_id, refunds.method, COUNT(*) AS n, SUM(refunds.amount) AS total')->get();
        $branches = Branch::query()->whereIn('id', $rows->pluck('branch_id')->merge($refunds->pluck('branch_id'))->filter()->unique())->get()->keyBy('id');

        $byBranch = $rows->groupBy('branch_id')->map(fn ($g, $branchId) => [
            'branch' => ['id' => $branchId ?: null, 'name' => $branchId && isset($branches[$branchId]) ? $branches[$branchId]->localized('name') : null],
            'total' => self::money(self::sum($g)),
            'by_method' => $g->groupBy('method')->map(fn ($m, $method) => ['method' => $method, 'count' => (int) $m->sum('n'), 'total' => self::money(self::sum($m))])->values(),
            'by_staff' => $g->groupBy('recorded_by')->map(fn ($s) => [
                'staff' => $s->first()->staff, 'count' => (int) $s->sum('n'), 'total' => self::money(self::sum($s)),
                'by_method' => $s->map(fn ($r) => ['method' => $r->method, 'total' => self::money($r->total)])->values(),
            ])->values(),
            'refunds' => $refunds->where('branch_id', $branchId)->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->n, 'total' => self::money($r->total)])->values(),
        ])->values();

        return response()->json(['data' => ['date' => $date, 'total' => self::money(self::sum($rows)), 'branches' => $byBranch]]);
    }

    /** الاستردادات: المنفذة، والمرتجعات المستلمة بانتظار استردادها */
    public function refunds(): JsonResponse
    {
        $done = Refund::query()->with(['payment.invoice', 'payment.salesOrder', 'creditNote', 'creator'])->orderByDesc('created_at')->limit(100)->get();
        $pending = ReturnRequest::query()->where('status', 'received')->with(['order', 'items.orderItem'])->orderBy('received_at')->get();

        return response()->json(['data' => [
            'pending_returns' => $pending->map(fn (ReturnRequest $r) => [
                'id' => $r->id, 'order' => ['id' => $r->order->id, 'number' => $r->order->number], 'reason' => $r->reason,
                'received_at' => $r->received_at?->toIso8601ZuluString(),
                'amount' => self::money(app(ReturnService::class)->amounts($r)['total']),
            ])->values(),
            'refunds' => $done->map(fn (Refund $r) => [
                'id' => $r->id, 'amount' => self::money((string) $r->amount), 'method' => $r->method, 'reason' => $r->reason,
                'reference' => $r->reference_text, 'at' => $r->created_at?->toIso8601ZuluString(), 'by' => $r->creator?->full_name,
                'credit_note' => $r->creditNote ? ['id' => $r->creditNote->id, 'number' => $r->creditNote->number] : null,
                'invoice' => $r->payment?->invoice ? ['id' => $r->payment->invoice->id, 'number' => $r->payment->invoice->number] : null,
                'order' => $r->payment?->salesOrder ? ['id' => $r->payment->salesOrder->id, 'number' => $r->payment->salesOrder->number] : null,
            ])->values(),
        ]]);
    }

    /** 6b.5: استرداد يدوي لدفعة، مع إشعار دائن (US-38) */
    public function refund(Request $request, string $paymentId): JsonResponse
    {
        abort_unless(Str::isUuid($paymentId), 404);
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,3', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'pos_card'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference_text' => [Rule::requiredIf(fn () => $request->input('method') === 'bank_transfer'), 'nullable', 'string', 'max:255'],
        ]);
        $data['amount'] = (string) $data['amount'];
        $refund = $this->invoices->refund(Payment::query()->findOrFail($paymentId), $this->staff($request), $data);

        return response()->json(['data' => ['id' => $refund->id, 'credit_note_id' => $refund->credit_note_id]], 201);
    }

    public function creditNotes(): JsonResponse
    {
        $rows = CreditNote::query()->with(['invoice', 'pdf'])->orderByDesc('issued_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (CreditNote $c) => InvoicePresenter::creditNote($c))->values()]);
    }

    /** @return array{0: Carbon, 1: Carbon} حدود الأيام بتوقيت مسقط */
    private function range(?string $from, ?string $to): array
    {
        $start = Carbon::parse($from ?? now('Asia/Muscat')->subDays(30)->toDateString(), 'Asia/Muscat')->startOfDay()->utc();
        $end = Carbon::parse($to ?? now('Asia/Muscat')->toDateString(), 'Asia/Muscat')->endOfDay()->utc();

        return [$start, $end];
    }

    /** @return array{amount: string, currency: string} */
    private static function money(mixed $v): array
    {
        return ['amount' => bcadd((string) $v, '0', 3), 'currency' => 'OMR'];
    }

    /** مجموع عشري دون أرقام عائمة */
    private static function sum(iterable $rows): string
    {
        $total = '0.000';
        foreach ($rows as $r) {
            $total = bcadd($total, (string) $r->total, 3);
        }

        return $total;
    }

    private function invoice(string $id): Invoice
    {
        abort_unless(Str::isUuid($id), 404);

        return Invoice::query()->with([...InvoicePresenter::WITH, 'items', 'payments.recorder', 'payments.branch', 'creditNotes.invoice'])->findOrFail($id);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
