<?php

namespace App\Modules\Service\Http\Controllers;

use App\Models\User;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use App\Modules\Service\Http\ServicePresenter;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Services\ReviewService;
use App\Modules\Service\Services\ServiceRequestService;
use App\Modules\Service\Support\Sla;
use App\Support\Money;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** طلبات الصيانة للعميل (C-15، C-16، M-15، M-16، FR-SRV-01). كل استعلام يمر بـ visibleTo (03 §13.1). */
class ServiceController
{
    /** تبويبات C-16 */
    private const TABS = [
        'active' => ['submitted', 'triaged', 'approved', 'in_service'],
        'awaiting' => ['awaiting_approval'],
        'done' => ['completed', 'closed'],
        'closed' => ['cancelled', 'declined'],
    ];

    public function __construct(private readonly ServiceRequestService $service) {}

    /** ما يحتاجه المعالج قبل التأكيد: رسوم الفحص (BR-11)، ومهلة الرد (BR-09)، وعنوان الورشة وساعاتها */
    public function meta(): JsonResponse
    {
        $fee = Setting::read('fees.inspection');
        $sla = Setting::read('sla.triage', ['normal_working_hours' => 4, 'urgent_hours' => 1]);
        $branch = Branch::query()->where('is_active', true)->orderBy('code')->first();

        return response()->json(['data' => [
            'inspection_fee' => $fee === null ? null : Money::of((string) $fee),
            'sla' => ['normal_working_hours' => (int) $sla['normal_working_hours'], 'urgent_hours' => (int) $sla['urgent_hours']],
            'expected' => ['normal' => Sla::due('normal', now())->toIso8601ZuluString(), 'urgent' => Sla::due('urgent', now())->toIso8601ZuluString()],
            'components' => ServiceRequest::COMPONENTS,
            /** @var array{name: string|null, address: string|null, phone: string|null, whatsapp: string|null, working_hours: array<string, mixed>}|null */
            'workshop' => $branch ? ['name' => $branch->localized('name'), 'address' => $branch->localized('address'), 'phone' => $branch->phone,
                'whatsapp' => $branch->whatsapp_service, 'working_hours' => $branch->working_hours] : null,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(self::rules($user));
        $sr = $this->service->create($user, $user, 'customer', $data);

        return response()->json(['data' => ServicePresenter::detail($this->load($sr->id))], 201);
    }

    /** قواعد الإنشاء المشتركة بين العميل والموظف (walk_in). المرفقات من رفع صاحب الطلب نفسه. */
    public static function rules(User $uploader): array
    {
        $own = Rule::exists('attachments', 'id')->where('uploaded_by', $uploader->id);

        return [
            'organization_id' => ['sometimes', 'nullable', 'uuid'],
            'customer_equipment_id' => ['nullable', 'uuid'],
            'new_equipment' => ['nullable', 'array'],
            'new_equipment.model_id' => ['nullable', 'uuid', Rule::exists('equipment_models', 'id')],
            'new_equipment.brand_text' => ['nullable', 'string', 'max:100'],
            'new_equipment.model_text' => ['nullable', 'string', 'max:100'],
            'new_equipment.serial_number' => ['nullable', 'string', 'max:100'],
            'component_type' => ['nullable', Rule::in(ServiceRequest::COMPONENTS)],
            'component_details' => ['nullable', 'string', 'max:1000'],
            'problem_description' => ['nullable', 'string', 'max:4000'],
            'voice_attachment_id' => ['nullable', 'uuid', $own],
            'attachments' => ['nullable', 'array', 'max:12'],
            'attachments.*.id' => ['required', 'uuid', 'distinct', $own],
            'attachments.*.kind' => ['required', Rule::in(['photo', 'video'])],
            'urgency' => ['required', Rule::in(['normal', 'urgent'])],
            'preferred_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'fees_acknowledged' => ['accepted'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'account' => ['nullable', 'string', 'max:36'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = ServiceRequest::query()->visibleTo($user)->forAccount($f['account'] ?? null);
        $counts = collect(self::TABS)->map(fn ($s) => (clone $base)->whereIn('status', $s)->count());
        $rows = (clone $base)->whereIn('status', self::TABS[$f['tab'] ?? 'active'])->with(ServicePresenter::WITH)
            ->orderByDesc('created_at')->offset($offset)->limit(21)->get();

        return response()->json([
            'data' => $rows->take(20)->map(fn (ServiceRequest $s) => ServicePresenter::row($s))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 20 ? OffsetCursor::encode($offset + 20) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => ServicePresenter::detail($this->find($this->user($request), $id))]);
    }

    /** 4.4: الإلغاء قبل بدء العمل */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->service->cancel($this->find($user, $id), $user, $data['reason'] ?? null);

        return $this->show($request, $id);
    }

    /** US-20، BR-10: تقييم واحد خلال المدة بعد الإغلاق */
    public function review(Request $request, string $id, ReviewService $reviews): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'timeliness_rating' => ['nullable', 'integer', 'between:1,5'],
            'quality_rating' => ['nullable', 'integer', 'between:1,5'],
            'communication_rating' => ['nullable', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'publish_consent' => ['nullable', 'boolean'],
        ]);
        $reviews->submit($this->find($user, $id), $user, $data);

        return $this->show($request, $id);
    }

    private function find(User $user, string $id): ServiceRequest
    {
        abort_unless(Str::isUuid($id), 404);

        return ServiceRequest::query()->visibleTo($user)->with(ServicePresenter::WITH)->findOrFail($id);
    }

    private function load(string $id): ServiceRequest
    {
        return ServiceRequest::query()->with(ServicePresenter::WITH)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
