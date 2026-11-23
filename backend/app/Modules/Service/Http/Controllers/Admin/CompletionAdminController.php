<?php

namespace App\Modules\Service\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Service\Http\CompletionPresenter;
use App\Modules\Service\Http\ServicePresenter;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Services\HandoverService;
use App\Modules\Service\Services\VerificationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * التحقق والإغلاق (A-09، FR-WO-05، US-34)، والعمل الإضافي (4a)، والتسليم (A-22، FR-SRV-09، US-49)، وقوالب الاختبار (A-18، FR-TEC-06).
 */
class CompletionAdminController
{
    public function __construct(private readonly VerificationService $verification, private readonly HandoverService $handovers) {}

    /** 5.12 الاعتماد، أو 5.13 "بلا تكلفة" بسبب */
    public function verify(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['no_charge' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $wo = $this->verification->verify($this->wo($id), $this->staff($request), (bool) ($data['no_charge'] ?? false), $data['reason'] ?? null);

        return response()->json(['data' => ['id' => $wo->id, 'status' => $wo->fresh()->status]]);
    }

    /** 5.11 إعادة للفني بملاحظة */
    public function returnToTechnician(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $wo = $this->verification->returnToTechnician($this->wo($id), $this->staff($request), $data['note']);

        return response()->json(['data' => ['id' => $wo->id, 'status' => $wo->status]]);
    }

    /** 4a.3 */
    public function rejectAdditional(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->verification->rejectAdditional($id, $this->staff($request), $data['reason']);

        return response()->json(['data' => ['id' => $id, 'status' => 'rejected']]);
    }

    /** تقدير إضافي: مسودة تُحرر في A-06 ثم تُرسل للعميل */
    public function estimateAdditional(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $q = $this->verification->estimateAdditional($id, $this->staff($request));

        return response()->json(['data' => ['quotation_id' => $q->id, 'rfq_id' => $q->rfq_id]], 201);
    }

    /** A-22 "جاهز للاستلام" مع أيام الانتظار والمبلغ المستحق */
    public function handovers(Request $request): JsonResponse
    {
        $rows = $this->handovers->readyQuery()->with(['customer', 'organization', 'equipment.equipmentModel.brand', 'intake', 'branch', 'workOrders', 'estimates'])
            ->orderBy('completed_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(function (ServiceRequest $sr) {
            $due = $this->handovers->due($sr);
            $since = $sr->completed_at ?? $sr->cancelled_at ?? $sr->updated_at;

            return ServicePresenter::row($sr, true) + [
                'card' => $sr->intake?->number,
                'due' => Money::of($due['due']),
                'invoice' => $due['invoice'] ? ['id' => $due['invoice']->id, 'number' => $due['invoice']->number, 'status' => $due['invoice']->status] : null,
                'inspection_fee_pending' => $due['fee_pending'],
                'waiting_days' => $since ? (int) $since->diffInDays(now()) : 0,
                'ready_since' => $since?->toIso8601ZuluString(),
            ];
        })->values()]);
    }

    /** US-49: الدفعة والتوقيع والاسم في معاملة واحدة، ثم إيصال التسليم */
    public function handOver(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = $request->validate([
            'receiver_name' => ['required', 'string', 'min:2', 'max:120'],
            'receiver_phone' => ['nullable', 'string', 'max:20'],
            'signature_id' => ['required', 'uuid'],
            'payment' => ['nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::in(Payment::MANUAL_METHODS)],
            'payment.amount' => ['required_with:payment', 'decimal:0,3', 'min:0.001'],
            'payment.reference_text' => ['nullable', 'string', 'max:120'],
        ]);
        $sr = ServiceRequest::query()->findOrFail($id);
        $row = $this->handovers->handOver($sr, $this->staff($request), $data);

        return response()->json(['data' => [
            'id' => $row->id, 'service_request_id' => $sr->id, 'status' => $sr->fresh()->status,
            'receipt_url' => CompletionPresenter::customer($sr->fresh(['workOrders', 'intake']))['handover']['receipt_url'] ?? null,
        ]], 201);
    }

    /** A-18: قوالب الاختبار (Q-20) */
    public function templates(): JsonResponse
    {
        return response()->json(['data' => DB::table('test_templates')->orderBy('applies_to')->get()->map(fn ($t) => [
            'id' => $t->id, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en, 'applies_to' => $t->applies_to,
            /** @var list<array<string, mixed>> */
            'fields' => json_decode((string) $t->fields, true) ?: [], 'is_active' => (bool) $t->is_active,
        ])->values()]);
    }

    public function updateTemplate(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        abort_unless(DB::table('test_templates')->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:20'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'fields.*.label_ar' => ['required', 'string', 'max:120'], 'fields.*.label_en' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', Rule::in(['number', 'boolean'])],
            'fields.*.unit' => ['nullable', 'string', 'max:20'],
            'fields.*.min' => ['nullable', 'numeric'], 'fields.*.max' => ['nullable', 'numeric'], 'fields.*.expect' => ['nullable', 'boolean'],
        ]);
        DB::table('test_templates')->where('id', $id)->update([
            'name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'is_active' => $data['is_active'],
            'fields' => json_encode(array_values($data['fields']), JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);

        return $this->templates();
    }

    private function wo(string $id): WorkOrder
    {
        abort_unless(Str::isUuid($id), 404);

        return WorkOrder::query()->findOrFail($id);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
