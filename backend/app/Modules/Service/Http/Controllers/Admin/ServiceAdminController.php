<?php

namespace App\Modules\Service\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Platform\Models\Branch;
use App\Modules\Service\Http\Controllers\ServiceController;
use App\Modules\Service\Http\ServicePresenter;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkshopIntake;
use App\Modules\Service\Services\IntakeService;
use App\Modules\Service\Services\ServiceRequestService;
use App\Modules\Service\Services\WorkOrderService;
use App\Support\OffsetCursor;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A-08 الفرز (FR-SRV-02، US-31) و A-22 الاستلام (FR-SRV-08، US-48) للمنسق والمدير. المنسق المقيّد بفرعه
 * يرى طلبات فرعه وما لم يُحدد فرعه بعد.
 */
class ServiceAdminController
{
    public function __construct(
        private readonly ServiceRequestService $service,
        private readonly WorkOrderService $workOrders,
        private readonly IntakeService $intakes,
    ) {}

    /** قائمة الفرز: submitted بالمهلة الأقرب والعاجل أولاً، وتبويب لكل الحالات */
    public function index(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...ServiceRequest::STATUSES, 'triage', 'open', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'received' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = $this->scoped($staff, ServiceRequest::query())
            ->when(trim((string) ($f['q'] ?? '')), function ($q, $text) {
                $phone = Search::phone($text);
                $q->where(fn ($w) => $w->where('number', 'like', Search::contains($text))
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', Search::contains($text))->when($phone, fn ($c2) => $c2->orWhere('phone_e164', 'like', $phone)))
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', Search::contains($text)))
                    ->orWhereHas('intake', fn ($i) => $i->where('number', 'like', Search::contains($text))));
            })
            ->when(isset($f['received']), fn ($q) => $f['received'] ? $q->whereHas('intake') : $q->whereDoesntHave('intake'));
        $counts = [
            'triage' => (clone $base)->where('status', 'submitted')->count(),
            'open' => (clone $base)->whereIn('status', ServiceRequest::OPEN)->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
        ];
        $status = $f['status'] ?? 'triage';
        $rows = (clone $base)
            ->when($status === 'triage', fn ($q) => $q->where('status', 'submitted')->orderByRaw("urgency = 'urgent' DESC")->orderBy('sla_due_at'))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ServiceRequest::OPEN)->orderByDesc('created_at'))
            ->when(! in_array($status, ['triage', 'open', 'all'], true), fn ($q) => $q->where('status', $status)->orderByDesc('created_at'))
            ->when($status === 'all', fn ($q) => $q->orderByDesc('created_at'))
            ->with(ServicePresenter::WITH)->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (ServiceRequest $s) => ServicePresenter::row($s, true))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $sr = $this->find($this->staff($request), $id);
        // سجل المعدة: طلبات سابقة لها (A-08)
        $history = $sr->customer_equipment_id
            ? ServiceRequest::query()->where('customer_equipment_id', $sr->customer_equipment_id)->where('id', '!=', $sr->id)
                ->orderByDesc('created_at')->limit(10)->get(['id', 'number', 'status', 'created_at'])
                ->map(fn ($h) => ['id' => $h->id, 'number' => $h->number, 'status' => $h->status, 'created_at' => $h->created_at?->toIso8601ZuluString()])
            : collect();

        return response()->json(['data' => ServicePresenter::detail($sr, true) + [
            'equipment_history' => $history->values(),
            // فروع الفرز (المنسق لا يملك شاشة الفروع)
            'branches' => Branch::query()->where('is_active', true)->orderBy('code')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->localized('name')])->values(),
        ]]);
    }

    /** 4.2: الفرز بالأولوية والفرع والمسار */
    public function triage(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $data = $request->validate([
            'route' => ['required', Rule::in(['direct_estimate', 'inspection'])], // no_approval مع الضمان والعقود (P2)
            'urgency' => ['required', Rule::in(['normal', 'urgent'])],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('is_active', true)],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->service->triage($this->find($staff, $id), $staff, $data['route'], $data['urgency'], $data['branch_id'], $data['instructions'] ?? null);

        return $this->show($request, $id);
    }

    /** 4.3 */
    public function decline(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->service->decline($this->find($staff, $id), $staff, $data['reason']);

        return $this->show($request, $id);
    }

    /** التقدير: مسودة عرض "صيانة" تُفتح في محرر العروض A-06 */
    public function estimate(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $q = $this->service->estimate($this->find($staff, $id), $staff);

        return response()->json(['data' => ['quotation_id' => $q->id, 'rfq_id' => $q->rfq_id]], 201);
    }

    /** 4.11: أمر الإصلاح لطلب معتمد */
    public function repair(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $data = $request->validate(['instructions' => ['nullable', 'string', 'max:2000']]);
        $wo = $this->workOrders->createRepair($this->find($staff, $id), $staff, $data['instructions'] ?? null);

        return response()->json(['data' => ['id' => $wo->id, 'number' => $wo->number]], 201);
    }

    /** US-48: عميل حاضر دون طلب، فيُنشأ الطلب (walk_in) ويُستلم في نفس الخطوة */
    public function walkIn(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $request->validate(['customer_id' => ['required', 'uuid']]);
        $customer = User::query()->where('user_type', 'customer')->where('status', 'active')->findOrFail($request->input('customer_id'));
        $data = $request->validate(ServiceController::rules($staff) + self::intakeRules($staff));
        $sr = DB::transaction(function () use ($customer, $staff, $data) {
            $sr = $this->service->create($customer, $staff, 'walk_in', $data);
            $this->intakes->receive($sr, $staff, $data);

            return $sr;
        });

        return response()->json(['data' => ServicePresenter::detail($this->find($staff, $sr->id), true)], 201);
    }

    /** FR-SRV-08: بطاقة الاستلام */
    public function intake(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $data = $request->validate(self::intakeRules($staff));
        $this->intakes->receive($this->find($staff, $id), $staff, $data);

        return $this->show($request, $id);
    }

    /** فتح الطلب بمسح ملصق QR أو كتابة رقم البطاقة */
    public function byCard(Request $request, string $number): JsonResponse
    {
        $intake = WorkshopIntake::query()->where('number', strtoupper(trim($number)))->firstOrFail();

        return response()->json(['data' => ['service_request_id' => $intake->service_request_id, 'number' => $intake->number]]);
    }

    public function receipt(Request $request, string $id): StreamedResponse
    {
        $sr = $this->find($this->staff($request), $id);
        abort_unless($sr->intake?->receipt, 404);

        return Storage::disk('local')->download($sr->intake->receipt->storage_key, "{$sr->intake->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    private static function intakeRules(User $staff): array
    {
        $own = Rule::exists('attachments', 'id')->where('uploaded_by', $staff->id);

        return [
            'brought_by_name' => ['required', 'string', 'max:190'],
            'brought_by_phone' => ['nullable', 'string', 'max:20'],
            'condition_notes' => ['nullable', 'string', 'max:2000'],
            'accessories' => ['nullable', 'string', 'max:2000'],
            'hour_meter_reading' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'hour_meter_missing_reason' => ['nullable', 'string', 'max:500'],
            'signature_id' => ['required', 'uuid', $own],
            'photo_ids' => ['nullable', 'array', 'max:20'],
            'photo_ids.*' => ['uuid', 'distinct', $own],
        ];
    }

    private function scoped(User $staff, $q)
    {
        $p = $staff->staffProfile;

        return $p && $p->restrict_to_branch && ! $staff->hasAbility('*')
            ? $q->where(fn ($w) => $w->where('branch_id', $p->branch_id)->orWhereNull('branch_id'))
            : $q;
    }

    private function find(User $staff, string $id): ServiceRequest
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->scoped($staff, ServiceRequest::query())->with(ServicePresenter::WITH)->findOrFail($id);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
