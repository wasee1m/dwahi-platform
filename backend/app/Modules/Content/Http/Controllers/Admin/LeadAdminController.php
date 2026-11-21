<?php

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Content\Models\Lead;
use App\Modules\Content\Models\LeadNote;
use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Platform\Support\Audit;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A-15 الاستفسارات (FR-CMS-05، US-43): قائمة، وإسناد، وتغيير حالة مع ملاحظة باسم الموظف ووقته.
 * "تحويل لطلب عرض سعر" يأتي مع وحدة طلبات العروض (S4).
 */
class LeadAdminController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Lead::STATUSES)],
            'topic' => ['nullable', Rule::in(['equipment', 'parts', 'service', 'other'])],
            'assigned' => ['nullable', 'in:me,none'],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $q = Lead::query()
            ->when($f['status'] ?? null, fn ($w, $v) => $w->where('status', $v))
            ->when($f['topic'] ?? null, fn ($w, $v) => $w->where('topic', $v))
            ->when(($f['assigned'] ?? null) === 'me', fn ($w) => $w->where('assigned_to', $request->user()->id))
            ->when(($f['assigned'] ?? null) === 'none', fn ($w) => $w->whereNull('assigned_to'))
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x->where('name', 'like', "%{$v}%")
                ->orWhere('company', 'like', "%{$v}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $v).'%')));
        $total = (clone $q)->count();
        $rows = $q->with(['branch', 'assignee'])->orderByRaw("status = 'new' DESC")->orderByDesc('created_at')
            ->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (Lead $l) => $this->row($l))->values(),
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
            'total' => $total,
            'counts' => Lead::query()->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $lead = $this->find($id);

        return response()->json(['data' => [
            ...$this->row($lead),
            'email' => $lead->email,
            'message' => $lead->message,
            'source_page' => $lead->source_page,
            'notes' => $lead->notes()->with('author')->get()->map(fn (LeadNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'status_from' => $n->status_from, 'status_to' => $n->status_to,
                'by' => $n->author?->full_name, 'at' => $n->created_at?->toIso8601ZuluString(),
            ])->values(),
        ]]);
    }

    /** PATCH /admin/leads/{id}: الحالة والإسناد والملاحظة في خطوة واحدة، وكل تغيير في السجل (US-43) */
    public function update(Request $request, string $id): JsonResponse
    {
        $lead = $this->find($id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Lead::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] && ! in_array($data['assigned_to'], $this->assigneeIds(), true)) {
            throw ValidationException::withMessages(['assigned_to' => [__('validation.in', ['attribute' => 'assigned_to'])]]);
        }

        $from = $lead->status;
        $to = $data['status'] ?? $from;
        $note = trim((string) ($data['note'] ?? ''));
        $assignChanged = array_key_exists('assigned_to', $data) && $data['assigned_to'] !== $lead->assigned_to;
        if ($to === $from && $note === '' && ! $assignChanged) {
            return $this->show($id);
        }

        DB::transaction(function () use ($lead, $data, $from, $to, $note, $assignChanged, $request) {
            $lead->update(['status' => $to] + ($assignChanged ? ['assigned_to' => $data['assigned_to']] : []));
            if ($to !== $from || $note !== '') {
                LeadNote::query()->create([
                    'lead_id' => $lead->id, 'author_id' => $request->user()->id, 'body' => $note ?: null,
                    'status_from' => $to !== $from ? $from : null, 'status_to' => $to !== $from ? $to : null,
                ]);
            }
            Audit::record('lead.updated', 'lead', $lead->id, ['status' => $from], ['status' => $to] + ($assignChanged ? ['assigned_to' => $data['assigned_to']] : []));
        });

        return $this->show($id);
    }

    /** GET /admin/leads/assignees: الموظفون النشطون الذين يتابعون الاستفسارات */
    public function assignees(): JsonResponse
    {
        return response()->json(['data' => User::query()->whereIn('id', $this->assigneeIds())->orderBy('full_name')->get(['id', 'full_name'])]);
    }

    /** @return list<string> */
    private function assigneeIds(): array
    {
        $roles = array_keys(array_filter(Permissions::ROLE_ABILITIES, fn ($a) => in_array('*', $a, true) || in_array('leads.manage', $a, true)));

        return DB::table('user_roles')->join('users', 'users.id', '=', 'user_roles.user_id')
            ->whereIn('user_roles.role', $roles)->where('users.status', 'active')
            ->distinct()->pluck('users.id')->map(fn ($v) => (string) $v)->all();
    }

    private function find(string $id): Lead
    {
        abort_unless(Str::isUuid($id), 404);

        return Lead::query()->with(['branch', 'assignee'])->findOrFail($id);
    }

    private function row(Lead $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'phone' => $l->phone,
            'company' => $l->company,
            'topic' => $l->topic,
            'status' => $l->status,
            'branch' => $l->branch?->code,
            'assigned_to' => $l->assignee ? ['id' => $l->assignee->id, 'name' => $l->assignee->full_name] : null,
            'created_at' => $l->created_at?->toIso8601ZuluString(),
        ];
    }
}
