<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Services\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** توثيق البريد من الملف الشخصي (C-02) */
class EmailController
{
    public function __construct(private readonly EmailVerification $emails) {}

    public function request(Request $request): JsonResponse
    {
        $code = $this->emails->request($request->user());

        return response()->json(['data' => [
            'status' => 'sent',
            /** @var string|null */
            'debug_code' => app()->environment('local') && config('mail.default') === 'log' ? $code : null,
        ]], 202);
    }

    public function verify(Request $request): MeResource
    {
        $code = $request->validate(['code' => ['required', 'digits:6']])['code'];
        $this->emails->verify($request->user(), $code);

        return new MeResource($request->user());
    }
}
