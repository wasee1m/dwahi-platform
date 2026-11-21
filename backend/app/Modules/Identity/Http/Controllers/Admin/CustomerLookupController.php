<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Identity\Support\Phone;
use App\Modules\Platform\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * العثور على عميل برقم جواله لإنشاء طلب نيابة عنه (A-05، US-43)، أو إنشاء حسابه برقمه.
 * الحساب المنشأ هكذا يدخل صاحبه بالرمز على رقمه، ويُكمل ملفه ويوافق على الشروط بنفسه (US-06).
 */
class CustomerLookupController
{
    public function lookup(Request $request): JsonResponse
    {
        $phone = Phone::normalize($request->validate(['phone' => ['required', 'string', 'max:30']])['phone']);
        $user = $phone ? User::query()->where('user_type', 'customer')->where('phone_e164', $phone)->first() : null;

        return response()->json(['data' => $user ? self::summary($user) : null, 'phone' => $phone]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'full_name' => ['required', 'string', 'max:255']]);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => [__('validation.custom.phone.regex')]]);
        }
        $user = User::query()->where('phone_e164', $phone)->first();
        if ($user) {
            abort_if($user->isStaff(), 422);

            return response()->json(['data' => self::summary($user)]);
        }
        $user = User::query()->create([
            'user_type' => 'customer', 'full_name' => $data['full_name'], 'phone_e164' => $phone,
            'preferred_locale' => 'ar', 'status' => 'active',
        ]);
        Audit::record('customer.created_by_staff', 'user', $user->id, null, ['phone' => $phone]);

        return response()->json(['data' => self::summary($user)], 201);
    }

    private static function summary(User $u): array
    {
        return ['id' => $u->id, 'full_name' => $u->full_name, 'phone' => $u->phone_e164, 'status' => $u->status, 'needs_onboarding' => $u->needsOnboarding()];
    }
}
