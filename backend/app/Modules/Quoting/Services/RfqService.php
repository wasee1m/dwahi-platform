<?php

namespace App\Modules\Quoting\Services;

use App\Models\User;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Parts\Models\Part;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Quoting\Models\Rfq;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** انتقالات طلب عرض السعر (SM-1). العروض نفسها في QuotationService. */
final class RfqService
{
    /** إعادة الفتح خلال 90 يوماً من الخسارة (SM-1 انتقال 1.9) */
    private const REOPEN_DAYS = 90;

    /**
     * 1.1: إنشاء الطلب من العميل أو نيابة عنه.
     *
     * @param  array{type: string, items: list<array<string, mixed>>, notes?: string|null, delivery_country?: string, delivery_address_text?: string|null, attachment_ids?: list<string>}  $data
     */
    public function create(User $customer, User $creator, string $source, array $data): Rfq
    {
        $orgId = DB::table('organization_members')->where('user_id', $customer->id)->whereNull('removed_at')->orderBy('joined_at')->value('organization_id');

        $rfq = DB::transaction(function () use ($customer, $creator, $source, $data, $orgId) {
            $items = $this->resolveItems($data['type'], $data['items']);
            $rfq = Rfq::query()->create([
                'number' => DocumentNumber::next('RFQ'),
                'type' => $data['type'],
                'customer_user_id' => $customer->id,
                'organization_id' => $orgId,
                'status' => 'submitted',
                'source' => $source,
                'created_by' => $creator->id,
                'branch_id' => $items['branch_id'] ?? Branch::query()->where('is_active', true)->orderBy('code')->value('id'),
                'delivery_country' => strtoupper($data['delivery_country'] ?? 'OM'),
                'delivery_address_text' => $data['delivery_address_text'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($items['rows'] as $i => $row) {
                $item = $rfq->items()->create(['line_no' => $i + 1] + collect($row)->except('attachment_ids')->all());
                foreach ($row['attachment_ids'] ?? [] as $a) {
                    $rfq->attachments()->attach($a, ['rfq_item_id' => $item->id]);
                }
            }
            foreach ($data['attachment_ids'] ?? [] as $a) {
                $rfq->attachments()->syncWithoutDetaching([$a => ['rfq_item_id' => null]]);
            }
            Audit::record('rfq.created', 'rfq', $rfq->id, null, ['number' => $rfq->number, 'source' => $source], $creator);

            return $rfq;
        });

        QuotingEvent::dispatch(QuotingEvent::RFQ_SUBMITTED, $rfq, null, $creator);

        return $rfq;
    }

    /**
     * البنود: معدة معروضة ومتاحة، أو قطعة ظاهرة، أو نص حر لقطعة غير مدرجة (FR-PRT-05). كل بند يحتاج واحداً على الأقل.
     *
     * @return array{rows: list<array<string, mixed>>, branch_id: string|null}
     */
    private function resolveItems(string $type, array $items): array
    {
        $rows = [];
        $branch = null;
        $errors = [];
        foreach ($items as $i => $it) {
            $text = trim((string) ($it['free_text'] ?? '')) ?: null;
            $listingId = $it['listing_id'] ?? null;
            $partId = $it['part_id'] ?? null;
            if (! $text && ! $listingId && ! $partId) {
                $errors["items.{$i}.free_text"] = [__('quoting.item_required')];

                continue;
            }
            if ($listingId) {
                $l = EquipmentListing::query()->whereKey($listingId)->first();
                if (! $l || $l->status !== 'published') {
                    $errors["items.{$i}.listing_id"] = [__('quoting.listing_unavailable')]; // BR-19

                    continue;
                }
                $branch ??= $l->branch_id;
            }
            if ($partId && ! Part::query()->whereKey($partId)->where('is_active', true)->exists()) {
                $errors["items.{$i}.part_id"] = [__('validation.exists', ['attribute' => 'part'])];

                continue;
            }
            if ($type === 'equipment' && $partId) {
                $errors["items.{$i}.part_id"] = [__('quoting.part_in_equipment_request')];

                continue;
            }
            $rows[] = [
                'listing_id' => $listingId, 'part_id' => $partId, 'free_text' => $text,
                'specs' => $it['specs'] ?? null, 'quantity' => (string) $it['quantity'], 'attachment_ids' => $it['attachment_ids'] ?? [],
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['rows' => $rows, 'branch_id' => $branch];
    }

    /** 1.3: العميل يلغي طلب معدات أو قطع لم يُرسل له أي عرض */
    public function cancel(Rfq $rfq, User $by, ?string $reason): Rfq
    {
        return $this->locked($rfq, function (Rfq $rfq) use ($by, $reason) {
            $sent = $rfq->quotations()->where('status', '!=', 'draft')->exists();
            if (! in_array($rfq->status, ['submitted', 'in_review'], true) || $sent || $rfq->type === 'service') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $rfq->status, 'to' => 'cancelled']);
            }
            $rfq->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'closed_at' => now()]);
            Audit::record('rfq.cancelled', 'rfq', $rfq->id, null, ['reason' => $reason], $by);

            return $rfq;
        });
    }

    /** 1.2: إسناد لموظف، فيصبح قيد المراجعة */
    public function assign(Rfq $rfq, User $staff, User $by): Rfq
    {
        return $this->locked($rfq, function (Rfq $rfq) use ($staff, $by) {
            if (in_array($rfq->status, ['won', 'cancelled'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $rfq->status, 'to' => 'in_review']);
            }
            $before = $rfq->assigned_to;
            $rfq->update(['assigned_to' => $staff->id, 'status' => $rfq->status === 'submitted' ? 'in_review' : $rfq->status]);
            Audit::record('rfq.assigned', 'rfq', $rfq->id, ['assigned_to' => $before], ['assigned_to' => $staff->id], $by);

            return $rfq;
        });
    }

    /** 1.4: خسارة بسبب إلزامي، N-25 للعميل */
    public function lose(Rfq $rfq, User $by, string $reason): Rfq
    {
        $rfq = $this->locked($rfq, function (Rfq $rfq) use ($by, $reason) {
            if ($rfq->status !== 'in_review' && $rfq->status !== 'submitted') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $rfq->status, 'to' => 'lost']);
            }
            $rfq->quotations()->where('status', 'draft')->delete();
            $rfq->update(['status' => 'lost', 'lost_reason' => $reason, 'closed_at' => now(), 'assigned_to' => $rfq->assigned_to ?? $by->id]);
            Audit::record('rfq.lost', 'rfq', $rfq->id, null, ['reason' => $reason], $by);

            return $rfq;
        });
        QuotingEvent::dispatch(QuotingEvent::RFQ_LOST, $rfq, null, $by, $reason);

        return $rfq;
    }

    /**
     * 1.9: إعادة فتح طلب خاسر خلال 90 يوماً. الموظف بسبب إلزامي، والعميل فقط لطلب معدات أو قطع خسر بانتهاء
     * الصلاحية ("اطلب تجديد العرض")، فيصل الموظف المسند N-03.
     */
    public function reopen(Rfq $rfq, User $by, ?string $reason): Rfq
    {
        $byCustomer = ! $by->isStaff();
        $rfq = $this->locked($rfq, function (Rfq $rfq) use ($by, $reason, $byCustomer) {
            $tooOld = $rfq->closed_at && $rfq->closed_at->lt(now()->subDays(self::REOPEN_DAYS));
            $customerBlocked = $byCustomer && ($rfq->lost_reason !== 'expired' || $rfq->type === 'service');
            if ($rfq->status !== 'lost' || $tooOld || $customerBlocked) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $rfq->status, 'to' => 'in_review']);
            }
            if (! $byCustomer && blank($reason)) {
                throw ValidationException::withMessages(['reason' => [__('validation.required', ['attribute' => 'reason'])]]);
            }
            $rfq->update(['status' => 'in_review', 'lost_reason' => null, 'closed_at' => null]);
            Audit::record('rfq.reopened', 'rfq', $rfq->id, ['status' => 'lost'], ['status' => 'in_review', 'reason' => $reason], $by);

            return $rfq;
        });
        if ($byCustomer) {
            QuotingEvent::dispatch(QuotingEvent::RENEWAL_REQUESTED, $rfq, $rfq->currentQuotation()->first(), $by);
        }

        return $rfq;
    }

    /** @template T @param callable(Rfq): T $fn @return T */
    private function locked(Rfq $rfq, callable $fn)
    {
        return DB::transaction(fn () => $fn(Rfq::query()->lockForUpdate()->findOrFail($rfq->id)));
    }
}
