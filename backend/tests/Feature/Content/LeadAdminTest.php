<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// A-15 (FR-CMS-05، US-43)

function postLead($test, array $over = []): string
{
    $test->postJson(api().'/public/leads', $over + [
        'name' => 'سالم', 'phone' => '91234567', 'topic' => 'equipment', 'message' => 'أريد حفاراً مستعملاً',
    ])->assertCreated();

    return (string) DB::table('leads')->latest('created_at')->value('id');
}

it('lists a new enquiry and records status changes and notes with the author (US-43)', function () {
    $id = postLead($this);
    $t = staffToken($this, 'sales');

    $list = $this->withToken($t)->getJson(api().'/admin/leads?status=new')->assertOk();
    expect($list->json('data.0.id'))->toBe($id)->and($list->json('counts.new'))->toBe(1);

    $me = User::query()->where('email', 'sales@dwahi.test')->value('id');
    $lead = $this->withToken($t)->patchJson(api()."/admin/leads/{$id}", ['status' => 'contacted', 'note' => 'اتصلت به، يريد عرضاً', 'assigned_to' => $me])
        ->assertOk()->json('data');

    expect($lead['status'])->toBe('contacted')
        ->and($lead['assigned_to']['id'])->toBe($me)
        ->and($lead['notes'][0])->toMatchArray(['body' => 'اتصلت به، يريد عرضاً', 'status_from' => 'new', 'status_to' => 'contacted', 'by' => User::query()->find($me)->full_name])
        ->and(DB::table('audit_logs')->where('action', 'lead.updated')->where('entity_id', $id)->exists())->toBeTrue();

    // ملاحظة دون تغيير حالة
    $again = $this->withToken($t)->patchJson(api()."/admin/leads/{$id}", ['note' => 'أرسلت الصور'])->assertOk()->json('data.notes');
    expect($again)->toHaveCount(2)->and($again[0]['status_to'])->toBeNull();
});

it('assigns enquiries only to staff who follow them', function () {
    $id = postLead($this);
    $t = staffToken($this, 'service_coordinator'); // المنسق يتابع الاستفسارات أيضاً (A-15)
    $parts = staffUser(['parts_staff'], 'parts@dwahi.test');

    $this->withToken($t)->patchJson(api()."/admin/leads/{$id}", ['assigned_to' => $parts->id])
        ->assertStatus(422)->assertJsonValidationErrors(['assigned_to'], 'error.details');
    $names = collect($this->withToken($t)->getJson(api().'/admin/leads/assignees')->assertOk()->json('data'))->pluck('id');
    expect($names)->not->toContain($parts->id);
});

it('keeps enquiries from roles without leads.manage', function () {
    $this->withToken(staffToken($this, 'parts_staff'))->getJson(api().'/admin/leads')->assertForbidden();
});
