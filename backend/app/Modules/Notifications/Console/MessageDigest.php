<?php

namespace App\Modules\Notifications\Console;

use App\Models\User;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Notifications\Support\Preferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * J-10 (02 §9): كل 15 دقيقة بريد واحد لكل مستخدم بالرسائل غير المقروءة (N-17) التي مضى عليها ربع ساعة ولم تُرسل بالبريد.
 * يُعطَّل من تفضيلات "الرسائل / البريد" (FR-NOT-03). متكرر الأمان: كل إشعار يُسجَّل في notification_deliveries بقناة email مرة.
 */
class MessageDigest extends Command
{
    protected $signature = 'messages:run {job : digest}';

    protected $description = 'J-10 email digest of unread messages';

    public function handle(): int
    {
        if ($this->argument('job') !== 'digest') {
            $this->error('job must be digest');

            return self::FAILURE;
        }
        $mailed = DB::table('notification_deliveries')->where('channel', 'email')->select('notification_id');
        $pending = DB::table('notifications')->where('event_code', 'N-17')->whereNull('read_at')
            ->where('created_at', '<=', now()->subMinutes(15))->where('created_at', '>=', now()->subDay())
            ->whereNotIn('id', $mailed)->orderBy('created_at')->get(['id', 'user_id', 'title', 'body', 'deep_link']);
        $sent = 0;
        foreach ($pending->groupBy('user_id') as $userId => $rows) {
            $user = User::query()->find($userId);
            $skip = ! $user || $user->status !== 'active' || ! $user->email || ! Preferences::enabled($user->id, 'messages', 'email');
            if (! $skip) {
                Mail::to($user->email)->send(new DigestMail($rows->all(), $user->preferred_locale === 'en' ? 'en' : 'ar'));
                $sent++;
            }
            foreach ($rows as $n) {
                DB::table('notification_deliveries')->insert([
                    'id' => (string) Str::uuid7(), 'notification_id' => $n->id, 'channel' => 'email', 'status' => $skip ? 'skipped' : 'sent',
                    'attempts' => 1, 'sent_at' => $skip ? null : now(), 'created_at' => now(),
                ]);
            }
        }
        $this->info("digests {$sent}");

        return self::SUCCESS;
    }
}
