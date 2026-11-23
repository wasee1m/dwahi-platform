<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\SalesOrder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** إشعارات الطلبات والفواتير (01 §8): N-05، N-06، N-07، N-16، N-23، N-26، N-27. بعد الالتزام فقط. */
class OrderNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(OrderEvent $e): void
    {
        $o = $e->order->loadMissing(['customer', 'organization', 'branch']);
        $link = "/account/orders/{$o->id}";
        $staffLink = "/admin/orders/{$o->id}";
        $total = number_format((float) $o->total, 3);
        $branch = fn (string $l) => $l === 'en' ? ($o->branch?->name_en ?: $o->branch?->name_ar) : $o->branch?->name_ar;

        match ($e->type) {
            OrderEvent::CONFIRMED => $this->confirmed($o, $link, $staffLink, $total),
            OrderEvent::READY => $this->notifier->notify($o->customerRecipients(), 'N-06',
                ['number' => $o->number, 'branch' => $branch, 'deadline' => $o->pickup_deadline_at?->timezone('Asia/Muscat')->format('Y-m-d H:i'), 'total' => $total],
                $link, [], 'N-06-ready'),
            OrderEvent::OUT_FOR_DELIVERY => $this->notifier->notify($o->customerRecipients(), 'N-06', ['number' => $o->number, 'total' => $total], $link, [], 'N-06-out'),
            OrderEvent::DELIVERED => $this->notifier->notify($o->customerRecipients(), 'N-06', ['number' => $o->number], $link, [], 'N-06-delivered'),
            OrderEvent::DELIVERY_FAILED => $this->notifier->notify($o->customerRecipients(), 'N-26', ['number' => $o->number, 'reason' => (string) $e->note], $link),
            // إلغاء العميل بنفسه لا يحتاج إشعاراً له، وإلغاء الموظف أو النظام يصله بالسبب
            OrderEvent::CANCELLED => $e->actor && ! $e->actor->isStaff()
                ? null : $this->notifier->notify($o->customerRecipients(), 'N-07', ['number' => $o->number, 'reason' => (string) $e->note], $link),
            OrderEvent::PICKUP_REMINDER => $this->notifier->notify($o->customerRecipients(), 'N-07',
                ['number' => $o->number, 'branch' => $branch, 'deadline' => $o->pickup_deadline_at?->timezone('Asia/Muscat')->format('Y-m-d H:i')],
                $link, [], 'N-07-reminder'),
            OrderEvent::RETURN_REQUESTED => $this->notifier->notify($this->partsStaff($o), 'N-27', ['number' => $o->number, 'reason' => (string) $e->note],
                '/admin/orders/returns', [], 'N-27-staff'),
            OrderEvent::RETURN_DECIDED => $this->notifier->notify($o->customerRecipients(), 'N-27', [
                'number' => $o->number, 'note' => (string) $e->note,
                'decision' => fn (string $l) => __('notifications.return_decisions.'.$e->return?->status, [], $l),
            ], $link),
            default => null,
        };
    }

    public function invoiceIssued(InvoiceIssued $e): void
    {
        $inv = $e->invoice;
        $this->notifier->notify($inv->customerRecipients(), 'N-16', ['number' => (string) $inv->number, 'total' => number_format((float) $inv->total, 3)],
            '/account/invoices');
    }

    /** N-05 للعميل، و N-23 لموظفي قطع الفرع */
    private function confirmed(SalesOrder $o, string $link, string $staffLink, string $total): void
    {
        $this->notifier->notify($o->customerRecipients(), 'N-05', ['number' => $o->number, 'total' => $total,
            'how' => fn (string $l) => __("notifications.fulfillment.{$o->fulfillment}", [], $l)], $link);
        $this->notifier->notify($this->partsStaff($o), 'N-23', [
            'number' => $o->number, 'total' => $total,
            'customer' => $o->organization?->name ?? $o->customer?->full_name ?? '',
            'phone' => fn (string $l) => $o->needs_phone_confirmation ? __('notifications.needs_phone', [], $l) : '',
        ], $staffLink);
    }

    /** موظفو القطع في فرع الطلب، وإن لم يوجد أحد فكل موظفي القطع */
    private function partsStaff(SalesOrder $o): Collection
    {
        $base = User::query()->where('user_type', 'staff')->where('status', 'active')
            ->whereIn('id', DB::table('user_roles')->where('role', 'parts_staff')->select('user_id'));
        $inBranch = (clone $base)->whereIn('id', DB::table('staff_profiles')->where('branch_id', $o->branch_id)->select('user_id'))->get();

        return $inBranch->isNotEmpty() ? $inBranch : $base->get();
    }
}
