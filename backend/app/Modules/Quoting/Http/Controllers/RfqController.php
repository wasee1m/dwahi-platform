<?php

namespace App\Modules\Quoting\Http\Controllers;

use App\Models\User;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Http\RfqPresenter;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Services\QuotationService;
use App\Modules\Quoting\Services\RfqService;
use App\Support\ApiProblem;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * طلبات العروض والعروض للعميل (C-07، C-08، C-09، FR-RFQ-01، FR-RFQ-03، FR-RFQ-04، FR-MSG-01).
 * كل استعلام يمر بـ Rfq::visibleTo (03 §13.1)، والطلب غير المرئي 404 لا 403 فلا يُكشف وجوده.
 */
class RfqController
{
    /** تبويبات C-08 */
    private const TABS = [
        'awaiting' => ['quoted'],
        'review' => ['submitted', 'in_review'],
        'done' => ['won'],
        'closed' => ['lost', 'cancelled'],
    ];

    public function __construct(private readonly RfqService $rfqs, private readonly QuotationService $quotes, private readonly Messenger $messenger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $f = $request->validate(['tab' => ['nullable', Rule::in(array_keys(self::TABS))], 'cursor' => ['nullable', 'string', 'max:50']]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = Rfq::query()->visibleTo($user);
        $counts = collect(self::TABS)->map(fn ($s) => (clone $base)->whereIn('status', $s)->count());
        $rows = (clone $base)->whereIn('status', self::TABS[$f['tab'] ?? 'awaiting'])
            ->with(['items.listing', 'items.part', 'quotations' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->orderByDesc('updated_at')->offset($offset)->limit(21)->get();

        return response()->json([
            'data' => $rows->take(20)->map(fn (Rfq $r) => RfqPresenter::row($r))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 20 ? OffsetCursor::encode($offset + 20) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        if ($user->needsOnboarding()) {
            throw new ApiProblem('ONBOARDING_REQUIRED', 403);
        }
        $data = $request->validate(self::rules($user));
        $rfq = $this->rfqs->create($user, $user, $data['source'] ?? 'web', $data);

        return response()->json(['data' => RfqPresenter::detail($this->load($rfq->id))], 201);
    }

    /** قواعد البنود (FR-RFQ-01، FR-PRT-05)، ومنها مواصفات السلندر. المرفقات من رفع المستخدم نفسه فقط. */
    public static function rules(User $uploader): array
    {
        $own = Rule::exists('attachments', 'id')->where('uploaded_by', $uploader->id);

        return [
            'type' => ['required', Rule::in(['equipment', 'parts'])],
            'source' => ['sometimes', Rule::in(['web', 'app'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'delivery_country' => ['nullable', 'string', 'size:2', 'alpha'],
            'delivery_address_text' => ['nullable', 'string', 'max:1000'],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['uuid', 'distinct', $own],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.listing_id' => ['nullable', 'uuid'],
            'items.*.part_id' => ['nullable', 'uuid'],
            'items.*.free_text' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'decimal:0,2', 'gt:0', 'max:100000'],
            'items.*.specs' => ['nullable', 'array:bore_mm,rod_mm,stroke_mm,mounting,pressure_bar'],
            'items.*.specs.bore_mm' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.specs.rod_mm' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'items.*.specs.stroke_mm' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'items.*.specs.mounting' => ['nullable', 'string', 'max:100'],
            'items.*.specs.pressure_bar' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'items.*.attachment_ids' => ['sometimes', 'array', 'max:5'],
            'items.*.attachment_ids.*' => ['uuid', 'distinct', $own],
        ];
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->customer($request);
        $rfq = $this->find($user, $id);

        return response()->json(['data' => [
            ...RfqPresenter::detail($rfq),
            'can_decide' => $this->quotes->canDecide($rfq, $user),
            'can_cancel' => in_array($rfq->status, ['submitted', 'in_review'], true) && $rfq->quotations->every(fn ($q) => $q->status === 'draft'),
            'can_renew' => $rfq->status === 'lost' && $rfq->lost_reason === 'expired' && $rfq->closed_at?->gt(now()->subDays(90)),
            'unread_messages' => $this->messenger->unreadCount($rfq, $user),
        ]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $this->customer($request);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $this->rfqs->cancel($this->find($user, $id), $user, $reason);

        return $this->show($request, $id);
    }

    /** "اطلب تجديد العرض" (SM-1 انتقال 1.9) */
    public function renew(Request $request, string $id): JsonResponse
    {
        $user = $this->customer($request);
        $this->rfqs->reopen($this->find($user, $id), $user, null);

        return $this->show($request, $id);
    }

    public function respond(Request $request, string $id, string $action): JsonResponse
    {
        $user = $this->customer($request);
        $q = $this->quotation($user, $id);
        $lpo = fn () => $request->validate([
            'lpo_number' => ['nullable', 'string', 'max:100'],
            'lpo_attachment_id' => ['nullable', 'uuid', Rule::exists('attachments', 'id')->where('uploaded_by', $user->id)],
        ]);
        match ($action) {
            'accept' => $this->quotes->accept($q, $user, $lpo()['lpo_number'] ?? null, $lpo()['lpo_attachment_id'] ?? null),
            'reject' => $this->quotes->reject($q, $user, $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null),
            'revision' => $this->quotes->requestRevision($q, $user, $request->validate(['note' => ['required', 'string', 'max:2000']])['note']),
            'request-approval' => $this->quotes->requestApproval($q, $user),
        };

        return $this->show($request, $q->rfq_id);
    }

    /** GET /me/quotations/{id}/pdf: النسخة المحفوظة عند الإرسال */
    public function pdf(Request $request, string $id): StreamedResponse
    {
        $q = $this->quotation($this->customer($request), $id);
        $pdf = Attachment::query()->findOrFail($q->pdf_id);

        return Storage::disk('local')->download($pdf->storage_key, "{$q->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $user = $this->customer($request);

        return response()->json(['data' => $this->messenger->thread($this->find($user, $id), $user)]);
    }

    public function postMessage(Request $request, string $id): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment_ids' => ['sometimes', 'array', 'max:5'],
            'attachment_ids.*' => ['uuid', 'distinct'],
        ]);
        $this->messenger->post($this->find($user, $id), $user, $data['id'], $data['body'] ?? null, $data['attachment_ids'] ?? []);

        return response()->json(['data' => $this->messenger->thread($this->find($user, $id), $user)], 201);
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isStaff(), 403); // الموظف يعمل من لوحة الإدارة (A-05)

        return $user;
    }

    private function find(User $user, string $id): Rfq
    {
        abort_unless(Str::isUuid($id), 404);

        return Rfq::query()->visibleTo($user)->with([...RfqPresenter::WITH, 'quotations.items', 'quotations.pdf'])->findOrFail($id);
    }

    private function load(string $id): Rfq
    {
        return Rfq::query()->with([...RfqPresenter::WITH, 'quotations.items'])->findOrFail($id);
    }

    private function quotation(User $user, string $id): Quotation
    {
        abort_unless(Str::isUuid($id), 404);
        $q = Quotation::query()->where('status', '!=', 'draft')->findOrFail($id);
        abort_unless(Rfq::query()->visibleTo($user)->whereKey($q->rfq_id)->exists(), 404);

        return $q;
    }
}
