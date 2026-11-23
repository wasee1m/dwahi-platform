<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Platform\Support\Audit;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Support\ApiProblem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * إنهاء العمل من الفني (SM-5 5.10، FR-TEC-05، FR-TEC-06، BR-07): نتيجة الاختبار بقالب نوع العمل، وطلب العمل الإضافي (4a.1)،
 * والتقرير مع قائمة التحقق قبل work_completed. الفني الرئيسي وحده يُنهي (04 §6.4 "التقرير النهائي").
 */
final class CompletionService
{
    public function __construct(private readonly ExecutionService $exec) {}

    /** القالب حسب نوع المكوّن، أو "المعدة كاملة"، وإلا العام */
    public static function templateFor(ServiceRequest $sr): ?object
    {
        $key = $sr->customer_equipment_id ? 'equipment' : ($sr->component_type ?? 'general');
        $rows = DB::table('test_templates')->where('is_active', true)->whereIn('applies_to', [$key, 'general'])->get();
        $t = $rows->firstWhere('applies_to', $key) ?? $rows->firstWhere('applies_to', 'general');
        if ($t) {
            $t->fields = json_decode((string) $t->fields, true) ?: [];
        }

        return $t;
    }

    /**
     * النتيجة من حدود القالب: الرقم بين min و max، والنعم/لا يطابق المتوقع. أي حقل خارجها = fail.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     */
    public static function evaluate(array $fields, array $values): string
    {
        foreach ($fields as $f) {
            $v = $values[$f['key']] ?? null;
            if (($f['type'] ?? 'number') === 'boolean') {
                if (! is_bool($v) || $v !== (bool) ($f['expect'] ?? true)) {
                    return 'fail';
                }

                continue;
            }
            if (! is_numeric($v)) {
                return 'fail';
            }
            if ((isset($f['min']) && $f['min'] !== null && (float) $v < (float) $f['min']) || (isset($f['max']) && $f['max'] !== null && (float) $v > (float) $f['max'])) {
                return 'fail';
            }
        }

        return 'pass';
    }

    /** test_recorded: القيم تُقيَّم في الخادم. الاختبار الفاشل يُسجَّل ويمنع الإنهاء حتى إعادة ناجحة (US-29). */
    public function recordTest(WorkOrder $wo, User $user, array $p, CarbonInterface $at): string
    {
        return DB::transaction(function () use ($wo, $user, $p, $at) {
            $wo = $this->locked($wo, $user, ['in_progress', 'on_hold']);
            $id = (string) ($p['id'] ?? '');
            if (! Str::isUuid($id)) {
                throw ValidationException::withMessages(['id' => [__('validation.uuid', ['attribute' => 'id'])]]);
            }
            if ($done = DB::table('work_order_tests')->where('id', $id)->value('result')) {
                return $done;
            }
            $sr = ServiceRequest::query()->findOrFail($wo->service_request_id);
            $template = self::templateFor($sr);
            $values = is_array($p['values'] ?? null) ? $p['values'] : [];
            if ($template && ($p['template_id'] ?? $template->id) !== $template->id) {
                $chosen = DB::table('test_templates')->where('id', $p['template_id'])->where('is_active', true)->first();
                if (! $chosen) {
                    throw ValidationException::withMessages(['template_id' => [__('validation.exists', ['attribute' => 'template'])]]);
                }
                $template = $chosen;
                $template->fields = json_decode((string) $chosen->fields, true) ?: [];
            }
            $fields = $template->fields ?? [];
            $keys = array_column($fields, 'key');
            $values = array_intersect_key($values, array_flip($keys));
            $result = self::evaluate($fields, $values);
            if (! empty($p['media_id'])) {
                $this->exec->ownUpload((string) $p['media_id'], $user);
            }
            DB::table('work_order_tests')->insert([
                'id' => $id, 'work_order_id' => $wo->id, 'template_id' => $template?->id, 'values' => json_encode($values, JSON_UNESCAPED_UNICODE),
                'result' => $result, 'notes' => $p['notes'] ?? null, 'media_id' => $p['media_id'] ?? null, 'tested_by' => $user->id, 'tested_at' => ExecutionService::ts($at),
            ]);
            if (! empty($p['media_id'])) {
                DB::table('work_order_photos')->insertOrIgnore(['work_order_id' => $wo->id, 'attachment_id' => $p['media_id'], 'phase' => 'test',
                    'caption' => null, 'taken_at' => ExecutionService::ts($at), 'taken_by' => $user->id, 'from_previous_technician' => false]);
            }
            Audit::record('work_order.test_recorded', 'work_order', $wo->id, null, ['test' => $id, 'result' => $result], $user);

            return $result;
        });
    }

    /** 4a.1: طلب عمل إضافي pending مع صورة، و N-13 للمنسق. "إيقاف بانتظار الموافقة" يوقف الأمر (5.8). */
    public function requestAdditional(WorkOrder $wo, User $user, array $p, CarbonInterface $at, ?string $clientEventId): void
    {
        $id = (string) ($p['id'] ?? '');
        $description = trim((string) ($p['description'] ?? ''));
        if (! Str::isUuid($id) || $description === '') {
            throw ValidationException::withMessages(['description' => [__('validation.required', ['attribute' => 'description'])]]);
        }
        $amount = $p['estimated_amount'] ?? null;
        if ($amount !== null && (! is_numeric($amount) || (float) $amount < 0)) {
            throw ValidationException::withMessages(['estimated_amount' => [__('validation.numeric', ['attribute' => 'estimated_amount'])]]);
        }
        $created = DB::transaction(function () use ($wo, $user, $p, $at, $id, $description, $amount) {
            $wo = $this->locked($wo, $user, ['in_progress', 'on_hold']);
            if (DB::table('additional_work_requests')->where('id', $id)->exists()) {
                return null;
            }
            DB::table('additional_work_requests')->insert([
                'id' => $id, 'work_order_id' => $wo->id, 'description' => $description, 'parts_needed' => $p['parts_needed'] ?? null,
                'estimated_amount' => $amount === null ? null : number_format((float) $amount, 3, '.', ''), 'status' => 'pending',
                'blocks_work' => (bool) ($p['hold'] ?? false), 'requested_by' => $user->id, 'requested_at' => ExecutionService::ts($at),
            ]);
            foreach (array_filter([$p['attachment_id'] ?? null, ...((array) ($p['attachment_ids'] ?? []))]) as $a) {
                $this->exec->ownUpload((string) $a, $user);
                DB::table('additional_work_request_attachments')->insertOrIgnore(['additional_work_request_id' => $id, 'attachment_id' => $a]);
            }
            Audit::record('work_order.additional_requested', 'work_order', $wo->id, null, ['request' => $id], $user);

            return $wo;
        });
        if (! $created) {
            return;
        }
        ServiceEvent::dispatch(ServiceEvent::ADDITIONAL_REQUESTED, $created->serviceRequest, $user, $description, $created);
        if (($p['hold'] ?? false) && $created->status === 'in_progress') {
            $this->exec->hold($created, $user, 'awaiting_approval', $at, $clientEventId, null, false); // N-13 أُرسل بطلب العمل الإضافي
        }
    }

    /**
     * قائمة BR-07 لأمر العمل، بما سُجل حتى الآن. الفحص يحتاج النتائج والوقت والعداد فقط (لا إصلاح ولا اختبار).
     *
     * @param  array{no_parts_used?: bool}  $report
     * @return array<string, bool>
     */
    public static function checklist(WorkOrder $wo, array $report = []): array
    {
        $sr = ServiceRequest::query()->findOrFail($wo->service_request_id);
        $inspection = $wo->type === 'inspection';
        $items = [
            'worked_time' => ExecutionService::workedSeconds($wo->id) > 0,
            'hour_meter' => ! $sr->customer_equipment_id || DB::table('hour_meter_readings')->where('work_order_id', $wo->id)->exists(),
        ];
        if (! $inspection) {
            $latest = DB::table('work_order_tests')->where('work_order_id', $wo->id)->orderByDesc('tested_at')->value('result');
            $items += [
                'after_photo' => DB::table('work_order_photos')->where('work_order_id', $wo->id)->where('phase', 'after')->exists(),
                'parts' => ($report['no_parts_used'] ?? false) || DB::table('work_order_parts')->where('work_order_id', $wo->id)->whereNull('removed_at')->exists(),
                'test_passed' => $latest === 'pass',
            ];
        }

        return $items;
    }

    /** report_submitted (5.10): التقرير وقائمة BR-07 كاملة، ثم work_completed وإيقاف الوقت و N-28 */
    public function finish(WorkOrder $wo, User $user, array $p, CarbonInterface $at, ?string $clientEventId): void
    {
        $findings = trim((string) ($p['findings'] ?? ''));
        $actions = trim((string) ($p['actions_taken'] ?? ''));
        if ($findings === '' || ($wo->type !== 'inspection' && $actions === '')) {
            throw ValidationException::withMessages(['findings' => [__('validation.required', ['attribute' => 'findings'])]]);
        }
        $wo = DB::transaction(function () use ($wo, $user, $p, $at, $clientEventId, $findings, $actions) {
            $wo = $this->locked($wo, $user, ['in_progress', 'on_hold']);
            $lead = DB::table('work_order_assignments')->where('work_order_id', $wo->id)->where('technician_user_id', $user->id)
                ->whereNull('unassigned_at')->value('role');
            if ($lead !== 'lead') {
                throw new ApiProblem('LEAD_ONLY', 403);
            }
            $missing = array_keys(array_filter(self::checklist($wo, ['no_parts_used' => (bool) ($p['no_parts_used'] ?? false)]), fn ($ok) => ! $ok));
            if ($missing) {
                throw new ApiProblem('CHECKLIST_INCOMPLETE', 422, null, ['missing' => $missing]);
            }
            $reading = DB::table('hour_meter_readings')->where('work_order_id', $wo->id)->orderByDesc('recorded_at')->value('reading');
            DB::table('service_reports')->updateOrInsert(['work_order_id' => $wo->id], [
                'findings' => $findings, 'actions_taken' => $actions, // الفحص بلا أعمال منفذة 'recommendations' => $p['recommendations'] ?? null,
                'hour_meter_reading' => $reading, 'no_parts_used' => (bool) ($p['no_parts_used'] ?? false),
                'submitted_by' => $user->id, 'submitted_at' => ExecutionService::ts($at), 'pdf_id' => null,
            ]);
            $from = $wo->status;
            $wo->update(['status' => 'work_completed', 'on_hold_reason' => null]);
            ExecutionService::closeEntries($wo->id, $at);
            $this->exec->event($wo, $user, $from, 'work_completed', null, $at, $clientEventId);

            return $wo;
        });
        ServiceEvent::dispatch(ServiceEvent::WO_COMPLETED, $wo->serviceRequest, $user, null, $wo);
    }

    /** @param list<string> $statuses */
    private function locked(WorkOrder $wo, User $user, array $statuses): WorkOrder
    {
        $wo = $this->exec->lockAssigned($wo, $user);
        if (! in_array($wo->status, $statuses, true)) {
            throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $wo->status]);
        }

        return $wo;
    }
}
