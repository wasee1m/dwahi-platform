<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// FR-MSG-01، FR-NOT-01، FR-NOT-02، US-21، US-23

beforeEach(fn () => Storage::fake('local'));

it('threads messages on a request, ignores retries and notifies the other side (US-21, N-17)', function () {
    $c = customerToken($this);
    $rfq = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'بستم', 'quantity' => 4]]])->json('data');
    $s = staffToken($this, 'sales');
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/assign")->assertOk();

    $img = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('p.png', 300, 300)], ['Accept' => 'application/json'])->json('data.id');
    $id = (string) Str::uuid7();
    asUser($this, $c)->postJson(api()."/me/rfqs/{$rfq['id']}/messages", ['id' => $id, 'body' => 'هذه صورة القطعة', 'attachment_ids' => [$img]])->assertCreated();
    asUser($this, $c)->postJson(api()."/me/rfqs/{$rfq['id']}/messages", ['id' => $id, 'body' => 'هذه صورة القطعة', 'attachment_ids' => [$img]])->assertCreated();

    $staffId = DB::table('users')->where('email', 'sales@dwahi.test')->value('id');
    expect(DB::table('messages')->count())->toBe(1)
        ->and(DB::table('notifications')->where('event_code', 'N-17')->where('user_id', $staffId)->count())->toBe(1);

    asUser($this, $s)->getJson(api()."/admin/rfqs/{$rfq['id']}")->assertJsonPath('data.unread_messages', 1);
    $thread = asUser($this, $s)->getJson(api()."/admin/rfqs/{$rfq['id']}/messages")->assertOk()->json('data');
    expect($thread[0]['mine'])->toBeFalse()->and($thread[0]['attachments'][0]['url'])->toContain('signature=');
    asUser($this, $s)->getJson(api()."/admin/rfqs/{$rfq['id']}")->assertJsonPath('data.unread_messages', 0);

    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/messages", ['id' => (string) Str::uuid7(), 'body' => 'متوفرة، نرسل العرض اليوم'])->assertCreated();
    expect(DB::table('notifications')->where('event_code', 'N-17')->where('user_id', '!=', $staffId)->count())->toBe(1);

    // مرفق لم يرفعه المرسل
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/messages", ['id' => (string) Str::uuid7(), 'attachment_ids' => [$img]])->assertStatus(422);
});

it('lists notifications with an unread count and marks them read (US-23)', function () {
    $c = customerToken($this);
    asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'فلتر', 'quantity' => 1]]])->assertCreated();

    $list = asUser($this, $c)->getJson(api().'/me/notifications')->assertOk();
    expect($list->json('unread_count'))->toBe(1)
        ->and($list->json('data.0.event'))->toBe('N-01')
        ->and($list->json('data.0.title'))->toStartWith('استلمنا طلب عرض السعر RFQ-')
        ->and($list->json('data.0.link'))->toStartWith('/account/quotes/');

    asUser($this, $c)->postJson(api().'/me/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
    // البريد في الطابور، ولكل قناة سجل حالة
    expect(DB::table('notification_deliveries')->where('channel', 'email')->count())->toBe(1);
});

it('respects channel preferences except for mandatory events (US-23)', function () {
    $c = customerToken($this);
    $user = DB::table('users')->where('phone_e164', '+96891234567')->value('id');
    DB::table('notification_preferences')->insert(['user_id' => $user, 'category' => 'messages', 'channel' => 'push', 'enabled' => false]);
    DB::table('notification_preferences')->insert(['user_id' => $user, 'category' => 'quotes', 'channel' => 'email', 'enabled' => false]);

    $rfq = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'فلتر', 'quantity' => 1]]])->json('data');
    $s = staffToken($this, 'sales');
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/messages", ['id' => (string) Str::uuid7(), 'body' => 'مرحباً'])->assertCreated();

    $deliveries = DB::table('notification_deliveries')->join('notifications', 'notifications.id', '=', 'notification_deliveries.notification_id')
        ->where('notifications.user_id', $user)->get(['event_code', 'channel']);
    // N-01 إلزامي: البريد يُرسل رغم التفضيل. N-17 غير إلزامي: لا Push
    expect($deliveries->where('event_code', 'N-01')->pluck('channel')->all())->toBe(['email'])
        ->and($deliveries->where('event_code', 'N-17')->count())->toBe(0);
});
