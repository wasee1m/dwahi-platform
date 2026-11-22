<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\User;
use App\Modules\Accounts\Http\AccountPresenter;
use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Identity\Support\Phone;
use App\Modules\Platform\Models\Branch;
use App\Support\Governorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** C-04 العناوين (FR-ACC-04، US-08). عناوين الشركة يديرها كل أعضائها، والحذف أرشفة (BR-17). */
class AddressController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['account' => ['nullable', 'string', 'max:36'], 'kind' => ['nullable', Rule::in(Address::KINDS)]]);
        $rows = Address::query()->visibleTo($request->user())->forAccount($f['account'] ?? null)->whereNull('archived_at')
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->whereIn('kind', [$k, 'both']))
            ->withCoordinates()->with('organization')->orderByDesc('addresses.created_at')->get();

        return response()->json(['data' => $rows->map(fn (Address $a) => AccountPresenter::address($a))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(Address::KINDS)],
            'governorate' => ['required', Rule::in(Governorates::ALL)],
            'wilayat' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ]);
        $org = $data['organization_id'] ?? null;
        if ($org && ! Memberships::includes($user, $org)) {
            throw ValidationException::withMessages(['organization_id' => [__('quoting.not_a_member')]]);
        }
        $a = Address::query()->create([...$this->columns($data), 'organization_id' => $org, 'user_id' => $org ? null : $user->id]);

        return response()->json(['data' => AccountPresenter::address($this->find($user, $a->id))], 201);
    }

    /** الحساب لا يتغير بالتعديل */
    public function update(Request $request, string $id): JsonResponse
    {
        $a = $this->find($request->user(), $id);
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'kind' => ['sometimes', 'required', Rule::in(Address::KINDS)],
            'governorate' => ['sometimes', 'required', Rule::in(Governorates::ALL)],
            'wilayat' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        Address::query()->whereKey($a->id)->update($this->columns($data));

        return response()->json(['data' => AccountPresenter::address($this->find($request->user(), $id))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $a = $this->find($request->user(), $id);
        Address::query()->whereKey($a->id)->update(['archived_at' => now()]);

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function columns(array $data): array
    {
        $out = collect($data)->except(['organization_id', 'latitude', 'longitude'])->all();
        if (! empty($data['contact_phone'])) {
            $out['contact_phone'] = Phone::normalize($data['contact_phone']);
            if (! $out['contact_phone']) {
                throw ValidationException::withMessages(['contact_phone' => [__('validation.custom.phone.regex')]]);
            }
        }
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $out['location'] = isset($data['latitude'], $data['longitude'])
                ? Branch::point((float) $data['latitude'], (float) $data['longitude'])
                : null;
        }

        return $out;
    }

    private function find(User $user, string $id): Address
    {
        abort_unless(Str::isUuid($id), 404);

        return Address::query()->visibleTo($user)->whereNull('archived_at')->withCoordinates()->with('organization')
            ->where('addresses.id', $id)->firstOrFail();
    }
}
