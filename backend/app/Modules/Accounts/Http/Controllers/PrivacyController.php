<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\User;
use App\Modules\Accounts\Jobs\BuildDataExport;
use App\Modules\Accounts\Models\DataExport;
use App\Modules\Accounts\Services\AccountDeletion;
use App\Modules\Platform\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** C-20: تنزيل بياناتي وحذف الحساب (FR-ACC-06، US-11) */
class PrivacyController
{
    public function __construct(private readonly AccountDeletion $deletion) {}

    public function exportStatus(Request $request): JsonResponse
    {
        $latest = DataExport::query()->where('user_id', $request->user()->id)->with('attachment')->orderByDesc('created_at')->first();

        return response()->json(['data' => $latest ? self::export($latest) : null]);
    }

    public function requestExport(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$export, $created] = DB::transaction(function () use ($user) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $pending = DataExport::query()->where('user_id', $user->id)->where('status', 'pending')
                ->where('created_at', '>', now()->subDay())->first();

            return $pending
                ? [$pending, false]
                : [DataExport::query()->create(['user_id' => $user->id, 'status' => 'pending']), true];
        });
        if ($created) {
            BuildDataExport::dispatch($export->id)->afterCommit();
        }
        $fresh = DataExport::query()->with('attachment')->findOrFail($export->id);

        return response()->json(['data' => self::export($fresh)], 202);
    }

    public function deletion(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->deletion->check($request->user()),
            'grace_days' => (int) Setting::read('account.deletion_grace_days', 30),
        ]]);
    }

    public function deletionCode(Request $request): JsonResponse
    {
        $code = $this->deletion->requestCode($request->user(), $request->ip(), app()->getLocale());

        return response()->json(['data' => [
            'status' => 'sent',
            /** @var string|null */
            'debug_code' => app()->environment('local') && config('services.sms.driver') === 'log' ? $code : null,
        ]], 202);
    }

    public function delete(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $this->deletion->delete($request->user(), $code);

        return response()->json(null, 204);
    }

    private static function export(DataExport $e): array
    {
        $ready = $e->status === 'ready' && $e->expires_at?->isFuture();

        return [
            'id' => $e->id,
            /** @var 'pending'|'ready'|'failed'|'expired' */
            'status' => $e->status === 'ready' && ! $ready ? 'expired' : $e->status,
            'requested_at' => $e->created_at->toIso8601ZuluString(),
            /** @var string|null */
            'expires_at' => $e->expires_at?->toIso8601ZuluString(),
            /** @var string|null */
            'url' => $ready ? $e->attachment?->accessUrl() : null,
        ];
    }
}
