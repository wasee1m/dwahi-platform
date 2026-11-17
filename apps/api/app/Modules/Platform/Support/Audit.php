<?php

namespace App\Modules\Platform\Support;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * سجل التدقيق (FR-ADM-04، NFR-10): من فعل ماذا ومتى، بالقيم قبل وبعد. إلحاقي فقط،
 * وقاعدة البيانات ترفض التعديل والحذف (Triggers).
 */
final class Audit
{
    /** المنفّذ هو المستخدم الحالي، أو يُمرَّر صراحة في خطوات الدخول قبل وجود جلسة */
    public static function record(string $action, string $entityType, ?string $entityId, ?array $before = null, ?array $after = null, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        $request = request();

        DB::table('audit_logs')->insert([
            'actor_user_id' => $actor?->id,
            'actor_roles' => $actor ? json_encode($actor->roleNames()) : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'trace_id' => Context::get('trace_id'),
            'created_at' => now(),
        ]);
    }
}
