<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Platform\Services\Uploads;
use App\Modules\Platform\Support\Audit;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkshopIntake;
use App\Modules\Service\Pdf\IntakePdf;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الاستلام في الورشة (FR-SRV-08، BR-20، US-48): بطاقة برقم RC وملصق QR، وصور الحالة، والملحقات، وقراءة العداد
 * للمعدة كاملة أو سبب تعذرها، واسم المُحضِر وتوقيعه، وإيصال PDF للعميل (N-32).
 */
final class IntakeService
{
    public function __construct(private readonly Uploads $uploads, private readonly IntakePdf $pdf) {}

    /**
     * @param  array{brought_by_name: string, brought_by_phone?: string|null, condition_notes?: string|null, accessories?: string|null,
     *               hour_meter_reading?: int|null, hour_meter_missing_reason?: string|null, signature_id: string, photo_ids?: list<string>}  $data
     */
    public function receive(ServiceRequest $sr, User $staff, array $data): WorkshopIntake
    {
        $intake = DB::transaction(function () use ($sr, $staff, $data) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
            if (! in_array($sr->status, ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $sr->status]);
            }
            if (WorkshopIntake::query()->where('service_request_id', $sr->id)->exists()) {
                throw new ApiProblem('ALREADY_RECEIVED', 409);
            }
            $reading = $data['hour_meter_reading'] ?? null;
            if ($sr->customer_equipment_id && $reading === null && blank($data['hour_meter_missing_reason'] ?? null)) {
                throw ValidationException::withMessages(['hour_meter_reading' => [__('service.meter_required')]]);
            }

            $intake = WorkshopIntake::query()->create([
                'number' => DocumentNumber::next('RC'),
                'service_request_id' => $sr->id,
                'received_at' => now(),
                'received_by' => $staff->id,
                'brought_by_name' => $data['brought_by_name'],
                'brought_by_phone' => $data['brought_by_phone'] ?? null,
                'condition_notes' => $data['condition_notes'] ?? null,
                'accessories' => $data['accessories'] ?? null,
                'hour_meter_reading' => $sr->customer_equipment_id ? $reading : null,
                'hour_meter_missing_reason' => $sr->customer_equipment_id && $reading === null ? $data['hour_meter_missing_reason'] : null,
                'signature_id' => $data['signature_id'],
            ]);
            $intake->photos()->sync($data['photo_ids'] ?? []);

            // قراءة الاستلام تدخل سجل عداد المعدة (US-10). الأقل من السابقة تُعلَّم للمراجعة ولا تغيّر العداد الحالي
            if ($sr->customer_equipment_id && $reading !== null) {
                $e = CustomerEquipment::query()->lockForUpdate()->find($sr->customer_equipment_id);
                $lower = $e->current_hour_meter !== null && $reading < $e->current_hour_meter;
                $e->readings()->create(['reading' => $reading, 'source' => 'intake', 'recorded_by' => $staff->id, 'recorded_at' => now(),
                    'needs_review' => $lower, 'note' => $lower ? __('service.intake_lower_reading') : null]);
                if (! $lower) {
                    $e->update(['current_hour_meter' => $reading, 'hour_meter_updated_at' => now()]);
                }
            }

            $intake->load(['serviceRequest.customer', 'serviceRequest.organization', 'serviceRequest.equipment.equipmentModel.brand', 'receiver', 'photos']);
            $pdf = $this->uploads->storeGenerated($this->pdf->render($intake), 'application/pdf', "{$intake->number}.pdf", $staff);
            $intake->update(['receipt_pdf_id' => $pdf->id]);
            Audit::record('service.received', 'workshop_intake', $intake->id, null, ['number' => $intake->number, 'service_request' => $sr->number], $staff);

            return $intake;
        });
        ServiceEvent::dispatch(ServiceEvent::RECEIVED, $intake->serviceRequest, $staff, $intake->number);

        return $intake;
    }
}
