<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محادثة الطلب بين العميل وفريق الشركة (FR-MSG-01). غير فورية: تتحدث عند الفتح وبإشعار (ADR-03).
 * الصلاحية (من يرى الطلب) يتحقق منها المتحكم قبل الاستدعاء.
 */
final class Messenger
{
    public function conversationFor(Rfq $rfq): Conversation
    {
        return Conversation::query()->firstOrCreate(['rfq_id' => $rfq->id]);
    }

    /** @param list<string> $attachmentIds */
    public function post(Rfq $rfq, User $sender, string $id, ?string $body, array $attachmentIds): Message
    {
        $body = trim((string) $body) ?: null;
        if (! $body && ! $attachmentIds) {
            throw ValidationException::withMessages(['body' => [__('validation.required', ['attribute' => 'body'])]]);
        }
        // المرفق من رفع المرسل نفسه فقط
        $own = Attachment::query()->whereIn('id', $attachmentIds)->where('uploaded_by', $sender->id)->count();
        if ($own !== count($attachmentIds)) {
            throw ValidationException::withMessages(['attachment_ids' => [__('validation.exists', ['attribute' => 'attachment'])]]);
        }

        $existing = Message::query()->find($id);
        if ($existing) {
            return $existing; // إعادة محاولة بنفس المعرّف
        }

        $message = DB::transaction(function () use ($rfq, $sender, $id, $body, $attachmentIds) {
            $c = $this->conversationFor($rfq);
            $m = Message::query()->create(['id' => $id, 'conversation_id' => $c->id, 'sender_user_id' => $sender->id, 'body' => $body, 'created_at' => now()]);
            $m->attachments()->attach($attachmentIds);
            $c->update(['last_message_at' => $m->created_at]);
            $this->markRead($c, $sender);

            return $m;
        });
        MessagePosted::dispatch($message->load('sender'), $rfq);

        return $message;
    }

    /** @return list<array<string, mixed>> */
    public function thread(Rfq $rfq, User $viewer): array
    {
        $c = Conversation::query()->where('rfq_id', $rfq->id)->first();
        if (! $c) {
            return [];
        }
        $messages = $c->messages()->with(['sender', 'attachments'])->limit(500)->get();
        $this->markRead($c, $viewer);

        return $messages->map(fn (Message $m) => [
            'id' => $m->id,
            'body' => $m->body,
            'mine' => $m->sender_user_id === $viewer->id,
            // العميل يرى "فريق الشركة" باسم الموظف الأول فقط، دون بريده أو دوره
            'sender' => ['name' => $m->sender?->full_name, 'is_staff' => (bool) $m->sender?->isStaff()],
            'attachments' => $m->attachments->map(fn (Attachment $a) => $a->summary())->values(),
            'at' => $m->created_at?->toIso8601ZuluString(),
        ])->values()->all();
    }

    public function unreadCount(Rfq $rfq, User $user): int
    {
        $c = Conversation::query()->where('rfq_id', $rfq->id)->first();
        if (! $c) {
            return 0;
        }
        $last = DB::table('conversation_reads')->where('conversation_id', $c->id)->where('user_id', $user->id)->value('last_read_at');

        return Message::query()->where('conversation_id', $c->id)->where('sender_user_id', '!=', $user->id)
            ->when($last, fn ($q) => $q->where('created_at', '>', $last))->count();
    }

    private function markRead(Conversation $c, User $user): void
    {
        DB::table('conversation_reads')->upsert(
            [['conversation_id' => $c->id, 'user_id' => $user->id, 'last_read_at' => now()->format('Y-m-d H:i:s.u')]],
            ['conversation_id', 'user_id'], ['last_read_at'],
        );
    }
}
