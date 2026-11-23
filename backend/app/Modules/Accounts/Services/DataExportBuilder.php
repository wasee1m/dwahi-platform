<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Http\AccountPresenter;
use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Quoting\Http\RfqPresenter;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Support\Facades\DB;

/**
 * محتوى "تنزيل بياناتي" (FR-ACC-06): بيانات الشخص نفسه. سجلات الشركة ليست بياناته الشخصية،
 * إلا الطلبات التي قدمها وما زال يراها. الملاحظات الداخلية لا تدخل أبداً.
 */
final class DataExportBuilder
{
    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $rows = fn ($collection) => collect($collection)->map(fn ($r) => (array) $r)->values()->all();

        $equipment = CustomerEquipment::query()->ownedBy($user, null)
            ->with(['organization', 'equipmentModel.brand', 'site', 'nameplate', 'readings'])->get();
        $rfqs = Rfq::query()->visibleTo($user)->where('customer_user_id', $user->id)
            ->with([...RfqPresenter::WITH, 'quotations.items'])->orderBy('created_at')->get();

        return [
            'generated_at' => now()->toIso8601ZuluString(),
            'profile' => [
                'full_name' => $user->full_name, 'phone' => $user->phone_e164, 'email' => $user->email,
                'preferred_locale' => $user->preferred_locale, 'created_at' => $user->created_at?->toIso8601ZuluString(),
            ],
            'consents' => $rows(DB::table('consents')->where('user_id', $user->id)->orderBy('created_at')->get(['kind', 'version', 'granted', 'ip', 'created_at'])),
            'companies' => $rows(DB::table('organization_members as m')->join('organizations as o', 'o.id', '=', 'm.organization_id')
                ->where('m.user_id', $user->id)->get(['o.name', 'm.role', 'm.can_approve_quotes', 'm.joined_at', 'm.removed_at'])),
            'addresses' => Address::query()->ownedBy($user, null)->withCoordinates()->with('organization')->get()
                ->map(fn (Address $a) => AccountPresenter::address($a))->all(),
            'fleet' => $equipment->map(fn (CustomerEquipment $e) => [
                // الروابط الموقعة تنتهي خلال 15 دقيقة، فلا معنى لها في ملف
                ...collect(AccountPresenter::equipment($e))->except('nameplate')->all(),
                'readings' => $e->readings->map->only(['reading', 'note', 'recorded_at'])->values()->all(),
            ])->all(),
            'quote_requests' => $rfqs->map(fn (Rfq $r) => $this->request($r))->all(),
            'parts_orders' => SalesOrder::query()->visibleTo($user)->where('customer_user_id', $user->id)->with('items')->orderBy('created_at')->get()
                ->map(fn (SalesOrder $o) => [
                    ...$o->only(['number', 'status', 'fulfillment', 'subtotal', 'delivery_fee', 'vat_total', 'total', 'notes', 'cancel_reason']),
                    'delivery_address' => $o->delivery_address['text'] ?? null,
                    'created_at' => $o->created_at?->toIso8601ZuluString(), 'delivered_at' => $o->delivered_at?->toIso8601ZuluString(),
                    'items' => $o->items->map->only(['part_number_snapshot', 'name_ar_snapshot', 'quantity', 'unit_price', 'line_total'])->values()->all(),
                ])->all(),
            'invoices' => Invoice::query()->visibleTo($user)->where('customer_user_id', $user->id)->where('status', '!=', 'draft')->orderBy('issue_date')->get()
                ->map(fn (Invoice $i) => $i->only(['number', 'status', 'subtotal', 'vat_total', 'total', 'amount_paid', 'buyer_snapshot'])
                    + ['issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString()])->all(),
            'messages' => $rows(DB::table('messages')->where('sender_user_id', $user->id)->orderBy('created_at')->get(['body', 'created_at'])),
            'notifications' => $rows(DB::table('notifications')->where('user_id', $user->id)->orderBy('created_at')->get(['event_code', 'title', 'body', 'created_at', 'read_at'])),
            'notification_preferences' => $rows(DB::table('notification_preferences')->where('user_id', $user->id)->get(['category', 'channel', 'enabled'])),
            'sessions' => $rows(DB::table('sessions')->where('user_id', $user->id)->orderBy('created_at')->get(['client', 'ip', 'user_agent', 'created_at', 'last_used_at', 'revoked_at'])),
        ];
    }

    /** @return array<string, mixed> */
    private function request(Rfq $r): array
    {
        $d = RfqPresenter::detail($r);

        return [
            ...collect($d)->only(['number', 'type', 'status', 'account', 'notes', 'delivery_country', 'delivery_address_text', 'created_at', 'closed_at'])->all(),
            'items' => collect($d['items'])->map(fn ($i) => collect($i)->only(['title', 'quantity', 'specs'])->all())->all(),
            'quotations' => collect($d['quotations'])->map(fn ($q) => collect($q)->only(['number', 'status', 'valid_until', 'subtotal', 'discount_total', 'vat_total', 'total'])->all())->all(),
        ];
    }
}
