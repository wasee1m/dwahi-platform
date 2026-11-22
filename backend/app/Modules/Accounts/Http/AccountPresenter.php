<?php

namespace App\Modules\Accounts\Http;

use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Models\Invitation;
use App\Modules\Identity\Models\Organization;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** شكل سجلات الحساب في الـ API للعميل والموظف */
final class AccountPresenter
{
    /** @return array{id: string, name: string}|null null = الحساب الشخصي */
    public static function account(?Organization $org): ?array
    {
        return $org ? ['id' => $org->id, 'name' => $org->name] : null;
    }

    public static function address(Address $a): array
    {
        return [
            'id' => $a->id,
            'account' => self::account($a->organization),
            'label' => $a->label,
            /** @var 'delivery'|'site'|'both' */
            'kind' => $a->kind,
            'governorate' => $a->governorate,
            'wilayat' => $a->wilayat,
            /** @var string|null */
            'description' => $a->description,
            /** @var float|null */
            'latitude' => $a->latitude === null ? null : round($a->latitude, 7),
            /** @var float|null */
            'longitude' => $a->longitude === null ? null : round($a->longitude, 7),
            /** @var string|null */
            'contact_name' => $a->contact_name,
            /** @var string|null */
            'contact_phone' => $a->contact_phone,
        ];
    }

    public static function equipment(CustomerEquipment $e): array
    {
        $model = $e->equipmentModel;

        return [
            'id' => $e->id,
            'account' => self::account($e->organization),
            'name' => $e->displayName(),
            'machine' => $e->machineName(),
            /** @var string|null */
            'nickname' => $e->nickname,
            /** @var string|null */
            'model_id' => $e->model_id,
            /** @var string|null */
            'brand' => $model ? $model->brand->name : $e->brand_text,
            /** @var string|null */
            'model' => $model ? $model->model_name : $e->model_text,
            /** @var string|null */
            'serial_number' => $e->serial_number,
            /** @var int|null */
            'year' => $e->year,
            /** @var int|null */
            'hour_meter' => $e->current_hour_meter,
            /** @var string|null */
            'hour_meter_at' => $e->hour_meter_updated_at?->toIso8601ZuluString(),
            /** @var array{id: string, label: string}|null */
            'site' => $e->site ? ['id' => $e->site->id, 'label' => $e->site->label] : null,
            /** @var array{id: string, name: string|null, mime_type: string, url: string|null}|null */
            'nameplate' => $e->nameplate?->summary(),
            'archived' => $e->archived_at !== null,
        ];
    }

    /**
     * الشركة بأعضائها. الجوالات والدعوات لمن يديرها فقط (المالك، ومدير النظام).
     */
    public static function organization(Organization $o, bool $manages, ?string $myRole, bool $showPhones): array
    {
        $members = DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $o->id)->whereNull('m.removed_at')
            ->orderByRaw("m.role = 'owner' DESC")->orderBy('m.joined_at')
            ->get(['u.id', 'u.full_name', 'u.phone_e164', 'u.status', 'm.role', 'm.can_approve_quotes']);
        $invitations = $manages || $showPhones
            ? Invitation::query()->open()->where('organization_id', $o->id)->orderByDesc('created_at')->get()
            : collect();

        return [
            'id' => $o->id,
            'name' => $o->name,
            /** @var string|null */
            'cr_number' => $o->cr_number,
            /** @var string|null */
            'vat_number' => $o->vat_number,
            'governorate' => $o->governorate,
            /** @var 'unverified'|'pending'|'verified'|'rejected' */
            'verification_status' => $o->verification_status,
            /** @var array{amount: string, currency: string} */
            'preapproval_limit' => Money::of($o->preapproval_limit),
            /** @var 'owner'|'member'|null */
            'my_role' => $myRole,
            'can_manage' => $manages,
            /** @var list<array{user_id: string, name: string, phone: string|null, status: string, role: 'owner'|'member', can_approve_quotes: bool}> */
            'members' => $members->map(fn ($m) => [
                'user_id' => $m->id, 'name' => $m->full_name, 'phone' => $showPhones ? $m->phone_e164 : null,
                'status' => $m->status, 'role' => $m->role, 'can_approve_quotes' => (bool) $m->can_approve_quotes,
            ])->values()->all(),
            /** @var list<array{id: string, phone: string|null, role: 'owner'|'member', can_approve_quotes: bool, expires_at: string}> */
            'invitations' => $invitations->map(fn (Invitation $i) => [
                'id' => $i->id, 'phone' => $i->phone_e164, 'role' => $i->role,
                'can_approve_quotes' => $i->can_approve_quotes, 'expires_at' => $i->expires_at->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }
}
