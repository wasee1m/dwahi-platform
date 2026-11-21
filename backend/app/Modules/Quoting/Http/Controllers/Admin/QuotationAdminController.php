<?php

namespace App\Modules\Quoting\Http\Controllers\Admin;

use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Support\PartNumber;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Setting;
use App\Modules\Quoting\Http\RfqPresenter;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Pdf\QuotationPdf;
use App\Modules\Quoting\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A-06 محرر عرض السعر (FR-RFQ-02، US-32): البنود والشروط والصلاحية، والمعاينة، والإرسال */
class QuotationAdminController
{
    public function __construct(private readonly QuotationService $quotes) {}

    public function show(string $id): JsonResponse
    {
        $q = $this->find($id);

        return response()->json(['data' => [
            ...RfqPresenter::quotation($q),
            'rfq' => ['id' => $q->rfq->id, 'number' => $q->rfq->number, 'type' => $q->rfq->type, 'status' => $q->rfq->status, 'delivery_country' => $q->rfq->delivery_country],
            'default_vat_rate' => (string) Setting::read('vat.default_rate', '5.00'), // للسطر الجديد (BR-01)
        ]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'estimated_duration' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:2000'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_type' => ['required', Rule::in(['equipment', 'part', 'labor', 'service_fee', 'other'])],
            'items.*.listing_id' => ['nullable', 'uuid', 'exists:equipment_listings,id'],
            'items.*.part_id' => ['nullable', 'uuid', 'exists:parts,id'],
            'items.*.description_ar' => ['required', 'string', 'max:1000'],
            'items.*.description_en' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'decimal:0,2', 'gt:0', 'max:100000'],
            'items.*.unit' => ['required', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'decimal:0,3', 'min:0', 'max:999999999'],
            'items.*.discount_amount' => ['nullable', 'decimal:0,3', 'min:0'],
            'items.*.vat_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'items.*.warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);
        $q = $this->quotes->update($this->find($id), $data, $request->user());

        return response()->json(['data' => RfqPresenter::quotation($q)]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $q = $this->quotes->send($this->find($id), $request->user());

        return response()->json(['data' => RfqPresenter::quotation($q->load('items'))]);
    }

    public function destroy(string $id): Response
    {
        $this->quotes->discard($this->find($id));

        return response()->noContent();
    }

    /** المسودة تُولَّد للمعاينة بعلامة DRAFT، والمرسل نسخته المحفوظة */
    public function pdf(string $id, QuotationPdf $pdf): Response|StreamedResponse
    {
        $q = $this->find($id);
        if ($q->status !== 'draft' && $q->pdf_id) {
            $a = Attachment::query()->findOrFail($q->pdf_id);

            return Storage::disk('local')->download($a->storage_key, "{$q->number}.pdf", ['Content-Type' => 'application/pdf']);
        }

        return response($pdf->render($q, preview: true), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="draft.pdf"']);
    }

    /** بحث الأصناف للمحرر: قطع بالرقم المطبّع أو الاسم، ومعدات منشورة بالعنوان */
    public function catalog(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $parts = Part::query()->with('brand')->where('is_active', true)
            ->where(fn ($w) => $w->where('part_number_normalized', 'like', PartNumber::normalize($q).'%')->orWhere('name_ar', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%"))
            ->limit(10)->get()->map(fn (Part $p) => [
                'item_type' => 'part', 'part_id' => $p->id, 'label' => "{$p->brand->name} {$p->part_number} · {$p->name_ar}",
                'description_ar' => "{$p->part_number} {$p->name_ar}", 'description_en' => "{$p->part_number} {$p->name_en}",
                'unit' => $p->unit, 'unit_price' => $p->price, 'vat_rate' => $p->vat_rate, 'warranty_months' => $p->warranty_months,
            ]);
        $listings = EquipmentListing::query()->where('status', 'published')
            ->where(fn ($w) => $w->where('title_ar', 'like', "%{$q}%")->orWhere('title_en', 'like', "%{$q}%"))
            ->limit(5)->get()->map(fn (EquipmentListing $l) => [
                'item_type' => 'equipment', 'listing_id' => $l->id, 'label' => $l->title_ar,
                'description_ar' => $l->title_ar, 'description_en' => $l->title_en, 'unit' => 'unit',
                'unit_price' => $l->price ?? '0.000', 'vat_rate' => null, 'warranty_months' => $l->warranty_months,
            ]);

        return response()->json(['data' => $listings->concat($parts)->values()]);
    }

    private function find(string $id): Quotation
    {
        abort_unless(Str::isUuid($id), 404);

        return Quotation::query()->with(['items', 'rfq'])->findOrFail($id);
    }
}
