<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Models\Consent;
use App\Modules\Notifications\Support\EventCatalog;
use App\Modules\Notifications\Support\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** C-20: مصفوفة الفئة × القناة، والموافقة التسويقية (FR-NOT-03، FR-ACC-06، US-23) */
class PreferenceController
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $saved = DB::table('notification_preferences')->where('user_id', $user->id)->get()
            ->keyBy(fn ($p) => "{$p->category}:{$p->channel}");

        $cells = [];
        foreach (EventCatalog::CATEGORIES as $category) {
            foreach (EventCatalog::CHANNELS as $channel) {
                $state = EventCatalog::state($category, $channel);
                $pref = $saved->get("{$category}:{$channel}");
                $cells[] = [
                    'category' => $category,
                    'channel' => $channel,
                    'state' => $state,
                    'has_mandatory' => EventCatalog::hasMandatory($category, $channel),
                    'enabled' => match ($state) {
                        'locked' => true,
                        'unused' => false,
                        default => $pref ? (bool) $pref->enabled : true,
                    },
                ];
            }
        }

        return response()->json(['data' => [
            'marketing_consent' => Preferences::marketingConsent($user->id),
            /** @var list<array{category: 'quotes'|'orders'|'service'|'messages'|'marketing', channel: 'push'|'email'|'sms', state: 'editable'|'locked'|'unused', has_mandatory: bool, enabled: bool}> */
            'preferences' => $cells,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rows = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:30'],
            'preferences.*.category' => ['required', Rule::in(EventCatalog::CATEGORIES)],
            'preferences.*.channel' => ['required', Rule::in(EventCatalog::CHANNELS)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ])['preferences'];

        $errors = [];
        foreach ($rows as $i => $row) {
            if (EventCatalog::state($row['category'], $row['channel']) !== 'editable') {
                $errors["preferences.{$i}.enabled"] = [__('accounts.locked_preference')];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        DB::table('notification_preferences')->upsert(
            array_map(fn ($r) => [
                'user_id' => $user->id, 'category' => $r['category'], 'channel' => $r['channel'], 'enabled' => (bool) $r['enabled'],
            ], $rows),
            ['user_id', 'category', 'channel'], ['enabled'],
        );

        return $this->show($request);
    }

    /** سجل الموافقات إلحاقي: كل تغيير صف جديد (NFR-13) */
    public function marketing(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $granted = (bool) $request->validate(['granted' => ['required', 'boolean']])['granted'];
        Consent::query()->create(['user_id' => $user->id, 'kind' => 'marketing', 'version' => 'v1', 'granted' => $granted, 'ip' => $request->ip()]);

        return $this->show($request);
    }
}
