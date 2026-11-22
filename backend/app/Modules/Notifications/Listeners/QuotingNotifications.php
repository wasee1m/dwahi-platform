<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** إشعارات العروض (01 §8): N-01، N-02، N-03، N-04، N-25، N-31. بعد الالتزام فقط، فلا إشعار لمعاملة أُلغيت. */
class QuotingNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(QuotingEvent $e): void
    {
        $rfq = $e->rfq->loadMissing(['customer', 'organization']);
        $q = $e->quotation;
        $customerLink = "/account/quotes/{$rfq->id}";
        $staffLink = "/admin/rfqs/{$rfq->id}";
        $total = $q ? number_format((float) $q->total, 3) : '';
        $customerName = $rfq->organization?->name ?? $rfq->customer?->full_name ?? '';

        match ($e->type) {
            QuotingEvent::RFQ_SUBMITTED => $this->submitted($rfq, $e->actor, $customerLink, $staffLink, $customerName),
            QuotingEvent::QUOTATION_SENT => $this->notifier->notify($rfq->customerRecipients(), 'N-02',
                ['number' => $q->number, 'rfq' => $rfq->number, 'total' => $total, 'valid_until' => $q->valid_until->format('Y-m-d')], $customerLink),
            QuotingEvent::QUOTATION_ACCEPTED => $this->toStaff($rfq, $q?->sent_by, 'accepted', $customerName, $staffLink, $q?->number),
            QuotingEvent::QUOTATION_REJECTED => $this->toStaff($rfq, $q?->sent_by, 'rejected', $customerName, $staffLink, $q?->number, $e->note),
            QuotingEvent::REVISION_REQUESTED => $this->toStaff($rfq, $q?->sent_by, 'revision', $customerName, $staffLink, $q?->number, $e->note),
            QuotingEvent::RENEWAL_REQUESTED => $this->toStaff($rfq, $q?->sent_by, 'renewal', $customerName, $staffLink, $q?->number ?? $rfq->number),
            QuotingEvent::RFQ_LOST => $this->notifier->notify($rfq->customerRecipients(), 'N-25', ['number' => $rfq->number, 'reason' => (string) $e->note], $customerLink),
            QuotingEvent::APPROVAL_REQUESTED => $this->notifier->notify($this->approvers($rfq), 'N-31',
                ['number' => $q->number, 'member' => $e->actor?->full_name ?? '', 'total' => $total], $customerLink),
            QuotingEvent::QUOTATION_EXPIRING => $this->notifier->notify($rfq->customerRecipients(), 'N-04',
                ['number' => $q->number, 'valid_until' => $q->valid_until->format('Y-m-d')], $customerLink),
            default => null,
        };
    }

    /** N-01: تأكيد للعميل، وتنبيه لفريق المبيعات إن قدّمه العميل بنفسه */
    private function submitted(Rfq $rfq, ?User $actor, string $customerLink, string $staffLink, string $customerName): void
    {
        $this->notifier->notify($rfq->customer, 'N-01', ['number' => $rfq->number], $customerLink);
        if ($actor && ! $actor->isStaff()) {
            $sales = User::query()->where('user_type', 'staff')->where('status', 'active')
                ->whereIn('id', DB::table('user_roles')->where('role', 'sales')->select('user_id'))->get();
            $type = $rfq->type;
            $this->notifier->notify($sales, 'N-01', ['number' => $rfq->number, 'customer' => $customerName,
                'type' => fn (string $l) => __("notifications.types.{$type}", [], $l)], $staffLink, [], 'N-01-staff');
        }
    }

    /** N-03 لموظف العرض: المرسل، وإلا المسند إليه */
    private function toStaff(Rfq $rfq, ?string $senderId, string $action, string $customerName, string $link, ?string $number, ?string $note = null): void
    {
        $user = User::query()->find($senderId ?? $rfq->assigned_to);
        if (! $user) {
            return;
        }
        $this->notifier->notify($user, 'N-03', [
            'number' => (string) $number, 'customer' => $customerName,
            'action' => fn (string $l) => __("notifications.actions.{$action}", ['note' => (string) $note], $l),
        ], $link);
    }

    private function approvers(Rfq $rfq): Collection
    {
        return User::query()->whereIn('id', DB::table('organization_members')->where('organization_id', $rfq->organization_id)
            ->whereNull('removed_at')->where(fn ($w) => $w->where('role', 'owner')->orWhere('can_approve_quotes', true))->select('user_id'))->get();
    }
}
