<?php

namespace App\Modules\Quoting\Services;

use App\Models\User;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Services\Uploads;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Pdf\QuotationPdf;
use App\Modules\Quoting\Support\QuoteMath;
use App\Support\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** انتقالات عرض السعر (SM-2، FR-RFQ-02، FR-RFQ-03، FR-RFQ-04) */
final class QuotationService
{
    public function __construct(private readonly Uploads $uploads, private readonly QuotationPdf $pdf) {}

    /**
     * 2.1: مسودة جديدة. البنود تُنسخ من آخر نسخة مرسلة (تعديل)، وإلا من الطلب بأسعار الكتالوج الحالية (US-32).
     * مسودة واحدة لكل طلب: إن وُجدت تُعاد.
     */
    public function draft(Rfq $rfq, User $staff): Quotation
    {
        return DB::transaction(function () use ($rfq, $staff) {
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
            if ($existing = $rfq->quotations()->where('status', 'draft')->first()) {
                return $existing;
            }
            if (! in_array($rfq->status, ['submitted', 'in_review'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $rfq->status, 'to' => 'draft']);
            }
            if ($rfq->status === 'submitted' || ! $rfq->assigned_to) {
                $rfq->update(['status' => 'in_review', 'assigned_to' => $rfq->assigned_to ?? $staff->id]);
            }

            $previous = $rfq->quotations()->where('status', '!=', 'draft')->with('items')->first();
            $q = $rfq->quotations()->create([
                'version' => (int) $rfq->quotations()->max('version') + 1,
                'status' => 'draft',
                'created_by' => $staff->id,
                'estimated_duration' => $previous?->estimated_duration,
                'delivery_terms' => $previous?->delivery_terms,
                'warranty_terms' => $previous?->warranty_terms,
                'payment_terms' => $previous?->payment_terms,
            ]);

            $lines = $previous
                ? $previous->items->map(fn ($i) => $i->only(['item_type', 'listing_id', 'part_id', 'description_ar', 'description_en', 'quantity', 'unit', 'unit_price', 'discount_amount', 'vat_rate', 'warranty_months']))->all()
                : $this->linesFromRfq($rfq);
            $this->writeLines($q, $lines);

            return $q->fresh('items');
        });
    }

    /** @return list<array<string, mixed>> */
    private function linesFromRfq(Rfq $rfq): array
    {
        $vat = (string) Setting::read('vat.default_rate', '5.00');

        return $rfq->items()->with(['listing.model.brand', 'part'])->get()->map(function ($it) use ($vat) {
            if ($l = $it->listing) {
                return ['item_type' => 'equipment', 'listing_id' => $l->id, 'description_ar' => $l->title_ar, 'description_en' => $l->title_en,
                    'quantity' => $it->quantity, 'unit' => 'unit', 'unit_price' => $l->price ?? '0', 'discount_amount' => '0', 'vat_rate' => $vat,
                    'warranty_months' => $l->warranty_months];
            }
            if ($p = $it->part) {
                return ['item_type' => 'part', 'part_id' => $p->id, 'description_ar' => "{$p->part_number} {$p->name_ar}", 'description_en' => "{$p->part_number} {$p->name_en}",
                    'quantity' => $it->quantity, 'unit' => $p->unit, 'unit_price' => $p->price, 'discount_amount' => '0', 'vat_rate' => $p->vat_rate,
                    'warranty_months' => $p->warranty_months];
            }

            return ['item_type' => 'other', 'description_ar' => $it->free_text, 'description_en' => $it->free_text,
                'quantity' => $it->quantity, 'unit' => 'piece', 'unit_price' => '0', 'discount_amount' => '0', 'vat_rate' => $vat];
        })->all();
    }

    /**
     * تعديل المسودة: البنود كاملة تُستبدل، والشروط والصلاحية. ممنوع بعد الإرسال (SM-2).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $q, array $data, User $staff): Quotation
    {
        return DB::transaction(function () use ($q, $data) {
            $q = Quotation::query()->lockForUpdate()->findOrFail($q->id);
            if ($q->status !== 'draft') {
                throw new ApiProblem('QUOTATION_LOCKED', 409);
            }
            $q->update(collect($data)->only(['valid_until', 'estimated_duration', 'delivery_terms', 'warranty_terms', 'payment_terms'])->all()
                + ['version_lock' => $q->version_lock + 1]);
            if (isset($data['items'])) {
                $q->items()->delete();
                $this->writeLines($q, $data['items']);
            }

            return $q->fresh('items');
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Quotation $q, array $lines): void
    {
        $rows = [];
        foreach (array_values($lines) as $i => $l) {
            $qty = (string) $l['quantity'];
            $price = number_format((float) $l['unit_price'], 3, '.', '');
            $discount = number_format((float) ($l['discount_amount'] ?? 0), 3, '.', '');
            $rate = number_format((float) $l['vat_rate'], 2, '.', '');
            try {
                $calc = QuoteMath::line($qty, $price, $discount, $rate);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["items.{$i}.discount_amount" => [__('quoting.discount_too_high')]]);
            }
            $rows[] = $q->items()->create([
                'line_no' => $i + 1, 'item_type' => $l['item_type'], 'listing_id' => $l['listing_id'] ?? null, 'part_id' => $l['part_id'] ?? null,
                'description_ar' => $l['description_ar'], 'description_en' => $l['description_en'], 'quantity' => $qty, 'unit' => $l['unit'],
                'unit_price' => $price, 'discount_amount' => $discount, 'vat_rate' => $rate, 'warranty_months' => $l['warranty_months'] ?? null,
            ] + $calc);
        }
        $q->update(QuoteMath::totals(array_map(fn ($r) => $r->only(['quantity', 'unit_price', 'discount_amount', 'line_subtotal', 'line_vat']), $rows)));
    }

    /** 2.8: حذف مسودة لم تُرسل */
    public function discard(Quotation $q): void
    {
        abort_unless($q->status === 'draft', 409);
        $q->delete();
    }

    /**
     * 2.2: الإرسال. رقم QT بنسخته، و PDF ثابت، والنسخ السابقة superseded، والطلب quoted، و N-02 (US-32).
     */
    public function send(Quotation $q, User $staff): Quotation
    {
        $q = DB::transaction(function () use ($q, $staff) {
            $q = Quotation::query()->lockForUpdate()->with(['items', 'rfq'])->findOrFail($q->id);
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($q->rfq_id);
            if ($q->status !== 'draft' || $rfq->status !== 'in_review') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $q->status, 'to' => 'sent']);
            }
            if ($q->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => [__('quoting.items_required')]]);
            }
            $validUntil = $q->valid_until ?? now('Asia/Muscat')->addDays((int) Setting::read('quote.validity_days', 14))->startOfDay();
            if (Carbon::parse($validUntil)->lt(now('Asia/Muscat')->startOfDay())) {
                throw ValidationException::withMessages(['valid_until' => [__('validation.after_or_equal', ['attribute' => 'valid_until', 'date' => 'today'])]]);
            }
            $this->assertListingsAvailable($q, $rfq);

            $previous = $rfq->quotations()->whereIn('status', ['sent', 'revision_requested'])->where('id', '!=', $q->id)->get();
            foreach ($previous as $old) {
                $old->update(['status' => 'superseded']); // 2.7، قبل الإرسال: نسخة فعالة واحدة
            }
            $base = $rfq->quotations()->whereNotNull('number')->value('number');
            $number = ($base ? preg_replace('/-v\d+$/', '', $base) : DocumentNumber::next('QT')).'-v'.$q->version;

            $q->update(['status' => 'sent', 'number' => $number, 'valid_until' => $validUntil, 'sent_by' => $staff->id, 'sent_at' => now()]);
            $q->refresh()->load(['items', 'rfq.customer', 'rfq.organization']);
            $pdf = $this->uploads->storeGenerated($this->pdf->render($q), 'application/pdf', "{$number}.pdf", $staff);
            $q->update(['pdf_id' => $pdf->id]);
            $rfq->update(['status' => 'quoted']);
            Audit::record('quotation.sent', 'quotation', $q->id, null, ['number' => $number, 'total' => $q->total, 'valid_until' => $q->valid_until->toDateString()], $staff);

            return $q;
        });
        QuotingEvent::dispatch(QuotingEvent::QUOTATION_SENT, $q->rfq, $q, $staff);

        return $q;
    }

    /** BR-19: لا عرض ولا قبول لمعدة محجوزة أو مباعة لغير هذا الطلب */
    private function assertListingsAvailable(Quotation $q, Rfq $rfq): void
    {
        $ids = $q->items->pluck('listing_id')->filter()->all();
        if (! $ids) {
            return;
        }
        $blocked = EquipmentListing::query()->whereIn('id', $ids)->lockForUpdate()->get()
            ->filter(fn ($l) => ! ($l->status === 'published' || ($l->status === 'reserved' && $l->reserved_for_rfq_id === $rfq->id)));
        if ($blocked->isNotEmpty()) {
            throw new ApiProblem('LISTING_UNAVAILABLE', 409, null, ['listing_ids' => $blocked->pluck('id')->values()]);
        }
    }

    /**
     * من يقرر عن العميل: الفرد نفسه، أو في الشركة المالك أو عضو بصلاحية "اعتماد العروض" (US-09، 2.3).
     */
    public function canDecide(Rfq $rfq, User $user): bool
    {
        if (! $rfq->organization_id) {
            return $rfq->customer_user_id === $user->id;
        }
        $m = DB::table('organization_members')->where('organization_id', $rfq->organization_id)->where('user_id', $user->id)->whereNull('removed_at')->first();

        return $m && ($m->role === 'owner' || $m->can_approve_quotes);
    }

    /** 2.3: القبول، مع رقم أمر الشراء أو ملفه مرجعاً (FR-RFQ-03، BR-08). يحجز المعدة (BR-19) والطلب won. */
    public function accept(Quotation $q, User $user, ?string $lpoNumber, ?string $lpoAttachmentId): Quotation
    {
        $q = $this->respond($q, $user, function (Quotation $q, Rfq $rfq) use ($user, $lpoNumber, $lpoAttachmentId) {
            if (! $this->canDecide($rfq, $user)) {
                throw new ApiProblem('APPROVAL_REQUIRED', 403);
            }
            $this->assertListingsAvailable($q, $rfq);
            $q->update(['status' => 'accepted', 'responded_by' => $user->id, 'responded_at' => now(), 'lpo_number' => $lpoNumber, 'lpo_attachment_id' => $lpoAttachmentId]);
            $rfq->update(['status' => 'won', 'closed_at' => now()]);
            // FR-RFQ-05: حجز المعدات فوراً في نفس المعاملة، والفاتورة (S6) وطلب القطع (S6) وأمر العمل (S7) من مستمعي الحدث
            EquipmentListing::query()->whereIn('id', $q->items->pluck('listing_id')->filter())->where('status', 'published')
                ->update(['status' => 'reserved', 'reserved_for_rfq_id' => $rfq->id]);
            Audit::record('quotation.accepted', 'quotation', $q->id, ['status' => 'sent'], ['status' => 'accepted', 'lpo_number' => $lpoNumber], $user);
        });
        QuotingEvent::dispatch(QuotingEvent::QUOTATION_ACCEPTED, $q->rfq, $q, $user);

        return $q;
    }

    /** 2.4: الرفض بسبب اختياري، والطلب lost */
    public function reject(Quotation $q, User $user, ?string $reason): Quotation
    {
        $q = $this->respond($q, $user, function (Quotation $q, Rfq $rfq) use ($user, $reason) {
            if (! $this->canDecide($rfq, $user)) {
                throw new ApiProblem('APPROVAL_REQUIRED', 403);
            }
            $q->update(['status' => 'rejected', 'responded_by' => $user->id, 'responded_at' => now(), 'response_note' => $reason]);
            $rfq->update(['status' => 'lost', 'lost_reason' => 'rejected'.($reason ? ": {$reason}" : ''), 'closed_at' => now()]);
            Audit::record('quotation.rejected', 'quotation', $q->id, ['status' => 'sent'], ['status' => 'rejected', 'reason' => $reason], $user);
        });
        QuotingEvent::dispatch(QuotingEvent::QUOTATION_REJECTED, $q->rfq, $q, $user, $reason);

        return $q;
    }

    /** 2.5: طلب تعديل بملاحظة إلزامية، والطلب يعود in_review. أي عضو يرى الطلب يستطيعه. */
    public function requestRevision(Quotation $q, User $user, string $note): Quotation
    {
        $q = $this->respond($q, $user, function (Quotation $q, Rfq $rfq) use ($user, $note) {
            $q->update(['status' => 'revision_requested', 'responded_by' => $user->id, 'responded_at' => now(), 'response_note' => $note]);
            $rfq->update(['status' => 'in_review']);
            Audit::record('quotation.revision_requested', 'quotation', $q->id, ['status' => 'sent'], ['status' => 'revision_requested'], $user);
        });
        QuotingEvent::dispatch(QuotingEvent::REVISION_REQUESTED, $q->rfq, $q, $user, $note);

        return $q;
    }

    /** عضو بلا صلاحية اعتماد يطلبها من المالك (N-31، US-13). مرة لكل نسخة. */
    public function requestApproval(Quotation $q, User $user): Quotation
    {
        $q = $this->respond($q, $user, function (Quotation $q, Rfq $rfq) use ($user) {
            if (! $rfq->organization_id || $this->canDecide($rfq, $user)) {
                throw new ApiProblem('INVALID_TRANSITION', 409);
            }
            if (! $q->approval_requested_at) {
                $q->update(['approval_requested_at' => now()]);
            }
        });
        QuotingEvent::dispatch(QuotingEvent::APPROVAL_REQUESTED, $q->rfq, $q, $user);

        return $q;
    }

    /** @param callable(Quotation, Rfq): void $fn */
    private function respond(Quotation $q, User $user, callable $fn): Quotation
    {
        return DB::transaction(function () use ($q, $fn) {
            $q = Quotation::query()->lockForUpdate()->with('items')->findOrFail($q->id);
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($q->rfq_id);
            if (! $q->isAcceptable()) {
                throw new ApiProblem($q->status === 'sent' ? 'QUOTATION_EXPIRED' : 'INVALID_TRANSITION', 409, null, ['status' => $q->status]);
            }
            $fn($q, $rfq);

            return $q->fresh(['items', 'rfq']);
        });
    }

    /** J-02 (كل ساعة): العروض المرسلة بعد يوم صلاحيتها بتوقيت مسقط تنتهي، والطلب lost بسبب expired (2.6) */
    public function expireDue(): int
    {
        $today = now('Asia/Muscat')->toDateString();
        $n = 0;
        Quotation::query()->where('status', 'sent')->where('valid_until', '<', $today)->pluck('id')->each(function ($id) use (&$n) {
            $q = DB::transaction(function () use ($id) {
                $q = Quotation::query()->lockForUpdate()->find($id);
                if (! $q || $q->status !== 'sent') {
                    return null; // تشغيل مزدوج: لا أثر مكرر
                }
                $q->update(['status' => 'expired']);
                $q->load('rfq');
                $q->rfq()->where('status', 'quoted')->update(['status' => 'lost', 'lost_reason' => 'expired', 'closed_at' => now()]);
                Audit::record('quotation.expired', 'quotation', $q->id, ['status' => 'sent'], ['status' => 'expired']);

                return $q;
            });
            if ($q) {
                $n++;
                QuotingEvent::dispatch(QuotingEvent::QUOTATION_EXPIRED, $q->rfq, $q);
            }
        });

        return $n;
    }

    /** J-03 (كل ساعة): تذكير واحد قبل 48 ساعة من نهاية يوم الصلاحية (N-04) */
    public function remindExpiring(): int
    {
        $limit = now('Asia/Muscat')->addHours(48)->subDay()->toDateString(); // نهاية يوم valid_until ≤ الآن + 48 ساعة
        $n = 0;
        Quotation::query()->where('status', 'sent')->whereNull('expiry_reminded_at')->where('valid_until', '<=', $limit)->pluck('id')
            ->each(function ($id) use (&$n) {
                $affected = Quotation::query()->whereKey($id)->whereNull('expiry_reminded_at')->update(['expiry_reminded_at' => now()]);
                if ($affected) {
                    $q = Quotation::query()->with('rfq')->find($id);
                    $n++;
                    QuotingEvent::dispatch(QuotingEvent::QUOTATION_EXPIRING, $q->rfq, $q);
                }
            });

        return $n;
    }
}
