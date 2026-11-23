<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Platform\Http\ApiErrorRenderer;
use App\Modules\Service\Models\WorkOrder;
use App\Support\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * الصندوق الصادر لتطبيق الفني (04 §6.2 إلى §6.4، ADR-05): الأحداث تُطبق بترتيبها، وكل حدث مرة واحدة بمعرّفه.
 * الرد لكل حدث: applied أو duplicate (بنتيجته الأصلية) أو rejected برمز ورسالة يعرضها مركز الإرسال (T-11).
 */
final class SyncService
{
    /** انحراف ساعة الجهاز المقبول قبل استبداله بوقت الاستلام */
    private const MAX_SKEW_SECONDS = 300;

    public const TYPES = ['status_changed', 'photo_added', 'note_added', 'part_used', 'part_removed', 'hour_meter_recorded'];

    public function __construct(private readonly ExecutionService $exec) {}

    /**
     * @param  list<array{client_event_id: string, work_order_id: string, type: string, occurred_at: string, payload?: array<string, mixed>}>  $events
     * @return list<array{client_event_id: string, result: string, code: string|null, message: string|null}>
     */
    public function apply(User $user, ?string $deviceId, array $events): array
    {
        $results = [];
        foreach ($events as $e) {
            $seen = DB::table('tech_sync_events')->where('client_event_id', $e['client_event_id'])->first();
            if ($seen) {
                $results[] = ['client_event_id' => $e['client_event_id'], 'result' => 'duplicate', 'code' => $seen->error_code, 'message' => null];

                continue;
            }
            $received = now();
            $at = Carbon::parse($e['occurred_at'])->utc();
            $adjusted = $at->gt($received->copy()->addSeconds(self::MAX_SKEW_SECONDS));
            if ($adjusted) {
                $at = $received->copy();
            }

            [$result, $code, $message] = ['applied', null, null];
            try {
                $this->one($user, $e, $at);
            } catch (ApiProblem $x) {
                [$result, $code] = ['rejected', $x->errorCode];
                $message = ApiErrorRenderer::message($x->errorCode);
            } catch (ValidationException $x) {
                [$result, $code, $message] = ['rejected', 'VALIDATION_FAILED', collect($x->errors())->flatten()->first()];
            } catch (Throwable $x) {
                report($x);
                [$result, $code] = ['rejected', 'INTERNAL_ERROR'];
            }
            DB::table('tech_sync_events')->insert([
                'client_event_id' => $e['client_event_id'], 'device_id' => $deviceId, 'user_id' => $user->id,
                'work_order_id' => WorkOrder::query()->whereKey($e['work_order_id'])->exists() ? $e['work_order_id'] : null,
                'type' => $e['type'], 'result' => $result, 'error_code' => $code, 'occurred_at' => ExecutionService::ts($at),
                'clock_adjusted' => $adjusted, 'received_at' => ExecutionService::ts($received),
            ]);
            $results[] = ['client_event_id' => $e['client_event_id'], 'result' => $result, 'code' => $code, 'message' => $message];
        }

        return $results;
    }

    /** @param array<string, mixed> $e */
    private function one(User $user, array $e, Carbon $at): void
    {
        $wo = WorkOrder::query()->find($e['work_order_id']);
        if (! $wo) {
            throw new ApiProblem('NOT_FOUND', 404);
        }
        $p = (array) ($e['payload'] ?? []);
        match ($e['type']) {
            'status_changed' => match ($p['to'] ?? null) {
                'in_progress' => $this->exec->start($wo, $user, $at, $e['client_event_id']),
                'on_hold' => $this->exec->hold($wo, $user, (string) ($p['reason'] ?? ''), $at, $e['client_event_id'], $p['note'] ?? null),
                default => throw new ApiProblem('INVALID_TRANSITION', 409), // الإنهاء (work_completed) مع S9
            },
            'photo_added' => $this->exec->addPhoto($wo, $user, (string) ($p['attachment_id'] ?? ''), (string) ($p['phase'] ?? ''), $p['caption'] ?? null, $at),
            'note_added' => $this->exec->addNote($wo, $user, $p['body'] ?? null, $p['voice_attachment_id'] ?? null, $at, $p['id'] ?? null),
            'part_used' => $this->exec->usePart($wo, $user, [
                'id' => (string) ($p['id'] ?? $e['client_event_id']), 'part_id' => $p['part_id'] ?? null, 'description' => $p['description'] ?? null,
                'quantity' => (string) ($p['quantity'] ?? '0'), 'source' => in_array($p['source'] ?? null, ['store', 'customer_supplied', 'purchased'], true) ? $p['source'] : 'store',
            ], $at),
            'part_removed' => $this->exec->removePart($wo, $user, (string) ($p['id'] ?? ''), $at),
            'hour_meter_recorded' => $this->exec->recordMeter($wo, $user, (int) ($p['reading'] ?? -1) >= 0 ? (int) $p['reading'] : throw ValidationException::withMessages(['reading' => [__('validation.required', ['attribute' => 'reading'])]]), $p['photo_id'] ?? null, $at),
            default => throw new ApiProblem('UNKNOWN_EVENT', 422),
        };
    }
}
