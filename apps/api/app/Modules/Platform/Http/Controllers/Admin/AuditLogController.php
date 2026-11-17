<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** سجل التدقيق للقراءة فقط (FR-ADM-04، US-44، A-19). لا توجد أي واجهة للتعديل أو الحذف. */
class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'uuid'],
            'actor_user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($f['limit'] ?? 50);

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->when($f['entity_type'] ?? null, fn ($q, $v) => $q->where('a.entity_type', $v))
            ->when($f['entity_id'] ?? null, fn ($q, $v) => $q->where('a.entity_id', $v))
            ->when($f['actor_user_id'] ?? null, fn ($q, $v) => $q->where('a.actor_user_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('a.action', 'like', $v.'%'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('a.created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('a.created_at', '<', $v))
            ->when($f['cursor'] ?? null, fn ($q, $v) => $q->where('a.id', '<', $v))
            ->orderByDesc('a.id')->limit($limit + 1)
            ->get(['a.*', 'u.full_name as actor_name']);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'action' => $r->action,
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'actor' => $r->actor_user_id ? ['id' => $r->actor_user_id, 'name' => $r->actor_name] : null,
                'actor_roles' => json_decode((string) $r->actor_roles, true) ?? [],
                'before' => json_decode((string) $r->before, true),
                'after' => json_decode((string) $r->after, true),
                'ip' => $r->ip,
                'trace_id' => $r->trace_id,
                'created_at' => Carbon::parse($r->created_at, 'UTC')->toIso8601ZuluString(),
            ])->values(),
            'next_cursor' => $hasMore ? (string) $rows->last()->id : null,
        ]);
    }
}
