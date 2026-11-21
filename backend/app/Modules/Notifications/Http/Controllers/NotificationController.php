<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Models\UserNotification;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** مركز الإشعارات (C-19، M-21، US-23) للعميل والموظف: كل مستخدم يرى إشعاراته فقط */
class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'category' => ['nullable', Rule::in(['quotes', 'orders', 'service', 'messages', 'marketing'])],
            'unread' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $rows = UserNotification::query()->where('user_id', $request->user()->id)
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('data->category', $v))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->offset($offset)->limit(31)->get();

        return response()->json([
            'data' => $rows->take(30)->map(fn (UserNotification $n) => [
                'id' => $n->id, 'event' => $n->event_code, 'category' => $n->data['category'] ?? null,
                'title' => $n->title, 'body' => $n->body, 'link' => $n->deep_link,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601ZuluString(),
            ])->values(),
            'unread_count' => $this->unread($request),
            'next_cursor' => $rows->count() > 30 ? OffsetCursor::encode($offset + 30) : null,
        ]);
    }

    /** للعداد في رأس الصفحة والتطبيق */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $this->unread($request)]]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        UserNotification::query()->where('user_id', $request->user()->id)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->count($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->count($request);
    }

    private function unread(Request $request): int
    {
        return UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count();
    }
}
