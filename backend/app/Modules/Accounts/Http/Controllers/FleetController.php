<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\User;
use App\Modules\Accounts\Http\AccountPresenter;
use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Models\HourMeterReading;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Quoting\Models\Rfq;
use App\Support\ApiProblem;
use App\Support\OffsetCursor;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** C-05، C-06، M-13، M-14: أسطولي (FR-ACC-05، US-10). معدات الشركة يديرها كل أعضائها. */
class FleetController
{
    public const WITH = ['organization', 'equipmentModel.brand', 'site', 'nameplate'];

    private const PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'account' => ['nullable', 'string', 'max:36'],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $rows = CustomerEquipment::query()->visibleTo($request->user())->forAccount($f['account'] ?? null)->whereNull('archived_at')
            ->when($f['q'] ?? null, function ($query, $v) {
                $like = Search::contains($v);
                $query->where(fn ($w) => $w->where('nickname', 'like', $like)->orWhere('serial_number', 'like', $like)
                    ->orWhere('brand_text', 'like', $like)->orWhere('model_text', 'like', $like)
                    ->orWhereHas('equipmentModel', fn ($m) => $m->where('model_name', 'like', $like)
                        ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))));
            })
            ->with(self::WITH)->orderByDesc('created_at')->orderBy('id')->offset($offset)->limit(self::PAGE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE)->map(fn (CustomerEquipment $e) => AccountPresenter::equipment($e))->values(),
            'next_cursor' => $rows->count() > self::PAGE ? OffsetCursor::encode($offset + self::PAGE) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'model_id' => ['nullable', 'uuid', Rule::exists('equipment_models', 'id')],
            'brand_text' => ['nullable', 'string', 'max:100'],
            'model_text' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'hour_meter' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'site_address_id' => ['nullable', 'uuid'],
            'nameplate_photo_id' => ['nullable', 'uuid', Rule::exists('attachments', 'id')->where('uploaded_by', $user->id)],
        ]);
        $org = $data['organization_id'] ?? null;
        if ($org && ! Memberships::includes($user, $org)) {
            throw ValidationException::withMessages(['organization_id' => [__('quoting.not_a_member')]]);
        }
        if (empty($data['model_id']) && blank($data['model_text'] ?? null)) {
            throw ValidationException::withMessages(['model_text' => [__('accounts.model_required')]]);
        }
        $this->assertSite($user, $org, $data['site_address_id'] ?? null);

        $id = DB::transaction(function () use ($user, $org, $data) {
            $meter = $data['hour_meter'] ?? null;
            $e = CustomerEquipment::query()->create([
                ...collect($data)->except(['organization_id', 'hour_meter'])->all(),
                'organization_id' => $org, 'owner_user_id' => $org ? null : $user->id,
                'current_hour_meter' => $meter, 'hour_meter_updated_at' => $meter === null ? null : now(),
            ]);
            if ($meter !== null) {
                $e->readings()->create(['reading' => $meter, 'source' => 'customer', 'recorded_by' => $user->id, 'recorded_at' => now()]);
            }

            return $e->id;
        });

        return response()->json(['data' => AccountPresenter::equipment($this->find($user, $id))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => AccountPresenter::equipment($this->find($request->user(), $id))]);
    }

    /** الحساب والعداد لا يتغيران من هنا: العداد بقراءة مسجلة */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $e = $this->find($user, $id);
        $data = $request->validate([
            'model_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('equipment_models', 'id')],
            'brand_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:2100'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site_address_id' => ['sometimes', 'nullable', 'uuid'],
            'nameplate_photo_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('attachments', 'id')->where('uploaded_by', $user->id)],
        ]);
        $after = $data + $e->only(['model_id', 'model_text']);
        if (! $after['model_id'] && blank($after['model_text'])) {
            throw ValidationException::withMessages(['model_text' => [__('accounts.model_required')]]);
        }
        $this->assertSite($user, $e->organization_id, $data['site_address_id'] ?? null);
        $e->update($data);

        return response()->json(['data' => AccountPresenter::equipment($this->find($user, $id))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $this->find($request->user(), $id)->update(['archived_at' => now()]);

        return response()->json(null, 204);
    }

    /** القراءة الأقل من السابقة: 409 للتحذير، ثم تُقبل بتأكيد وملاحظة وتُعلَّم للمراجعة */
    public function addReading(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $e = $this->find($user, $id);
        $data = $request->validate([
            'reading' => ['required', 'integer', 'min:0', 'max:999999'],
            'confirm_lower' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'photo_id' => ['nullable', 'uuid', Rule::exists('attachments', 'id')->where('uploaded_by', $user->id)],
        ]);

        DB::transaction(function () use ($e, $user, $data) {
            $locked = CustomerEquipment::query()->lockForUpdate()->findOrFail($e->id);
            $value = (int) $data['reading'];
            $lower = $locked->current_hour_meter !== null && $value < $locked->current_hour_meter;
            if ($lower && ! ($data['confirm_lower'] ?? false)) {
                throw new ApiProblem('HOUR_METER_LOWER', 409, null, ['previous' => $locked->current_hour_meter]);
            }
            if ($lower && blank($data['note'] ?? null)) {
                throw ValidationException::withMessages(['note' => [__('accounts.note_required')]]);
            }
            $locked->readings()->create([
                'reading' => $value, 'source' => 'customer', 'note' => $data['note'] ?? null, 'needs_review' => $lower,
                'photo_id' => $data['photo_id'] ?? null, 'recorded_by' => $user->id, 'recorded_at' => now(),
            ]);
            $locked->update(['current_hour_meter' => $value, 'hour_meter_updated_at' => now()]);
        });

        return response()->json(['data' => AccountPresenter::equipment($this->find($user, $id))], 201);
    }

    /** السجل الزمني: القراءات والطلبات المرتبطة. الصيانة والتقارير تنضم في S7 و S9. */
    public function history(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $e = $this->find($user, $id);
        $readings = $e->readings()->with(['recorder', 'photo'])->limit(100)->get()->map(fn (HourMeterReading $r) => [
            'type' => 'reading', 'id' => $r->id, 'at' => $r->recorded_at->toIso8601ZuluString(),
            'reading' => $r->reading, 'note' => $r->note, 'needs_review' => $r->needs_review,
            'by' => $r->recorder?->full_name, 'photo_url' => $r->photo?->accessUrl(), 'number' => null, 'status' => null,
        ]);
        $requests = Rfq::query()->visibleTo($user)->where('customer_equipment_id', $e->id)->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (Rfq $r) => [
                'type' => 'rfq', 'id' => $r->id, 'at' => $r->created_at->toIso8601ZuluString(),
                'reading' => null, 'note' => null, 'needs_review' => false,
                'by' => null, 'photo_url' => null, 'number' => $r->number, 'status' => $r->status,
            ]);

        $items = collect([...$readings, ...$requests])->sortByDesc('at')->values()->all();

        return response()->json([
            /** @var list<array{type: 'reading'|'rfq', id: string, at: string, reading: int|null, note: string|null, needs_review: bool, by: string|null, photo_url: string|null, number: string|null, status: string|null}> */
            'data' => $items,
        ]);
    }

    private function assertSite(User $user, ?string $org, ?string $addressId): void
    {
        if ($addressId && ! Address::query()->ownedBy($user, $org)->whereNull('archived_at')->whereKey($addressId)->exists()) {
            throw ValidationException::withMessages(['site_address_id' => [__('accounts.address_not_in_account')]]);
        }
    }

    private function find(User $user, string $id): CustomerEquipment
    {
        abort_unless(Str::isUuid($id), 404);

        return CustomerEquipment::query()->visibleTo($user)->whereNull('archived_at')->with(self::WITH)->findOrFail($id);
    }
}
