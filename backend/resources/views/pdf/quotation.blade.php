@php
    $money = fn ($v) => number_format((float) $v, 3);
    $customer = $rfq->organization?->name ?? $rfq->customer?->full_name;
@endphp
<html dir="rtl">
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; color: #231f1a; }
    .muted { color: #6b6257; }
    .en { direction: ltr; text-align: left; }
    h1 { font-size: 16pt; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .items th { background: #231f1a; color: #fff; padding: 5px; font-size: 8.5pt; }
    .items td { border-bottom: 1px solid #ddd3c2; padding: 5px; vertical-align: top; }
    .num { direction: ltr; text-align: left; white-space: nowrap; }
    .totals td { padding: 3px 5px; }
    .grand td { font-weight: bold; font-size: 11pt; border-top: 2px solid #231f1a; }
    .box { border: 1px solid #ddd3c2; padding: 6px; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%">
            <h1>{{ $company['name_ar'] ?? '' }}</h1>
            <div class="en">{{ $company['name_en'] ?? '' }}</div>
            <div class="muted">{{ $company['address_ar'] ?? '' }}</div>
            @if(!empty($company['cr_number']))<div class="muted">السجل التجاري / CR: <span class="num">{{ $company['cr_number'] }}</span></div>@endif
            @if(!empty($company['vat_number']))<div class="muted">الرقم الضريبي / VATIN: <span class="num">{{ $company['vat_number'] }}</span></div>@endif
        </td>
        <td style="width:45%; text-align:left">
            <h1>عرض سعر · <span class="en">Quotation</span></h1>
            <div class="num"><b>{{ $q->number ?? 'DRAFT' }}</b></div>
            <div>طلب / RFQ: <span class="num">{{ $rfq->number }}</span></div>
            <div>التاريخ / Date: <span class="num">{{ ($q->sent_at ?? now())->timezone('Asia/Muscat')->format('Y-m-d') }}</span></div>
            <div>صالح حتى / Valid until: <span class="num">{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</span></div>
        </td>
    </tr>
</table>

<div class="box" style="margin-top:8px">
    العميل / Customer: <b>{{ $customer }}</b>
    @if($rfq->customer?->phone_e164) · <span class="num">{{ $rfq->customer->phone_e164 }}</span>@endif
    @if($rfq->delivery_country !== 'OM') · التصدير إلى / Export to: <span class="num">{{ $rfq->delivery_country }}</span>@endif
</div>

<table class="items" style="margin-top:10px">
    <thead>
        <tr>
            <th style="width:4%">#</th>
            <th>البيان / Description</th>
            <th style="width:9%">الكمية<br>Qty</th>
            <th style="width:12%">السعر<br>Unit price</th>
            <th style="width:10%">الخصم<br>Discount</th>
            <th style="width:8%">الضريبة<br>VAT %</th>
            <th style="width:13%">الإجمالي<br>Total</th>
        </tr>
    </thead>
    <tbody>
    @foreach($q->items as $i)
        <tr>
            <td class="num">{{ $i->line_no }}</td>
            <td>
                {{ $i->description_ar }}
                @if($i->description_en !== $i->description_ar)<div class="en muted">{{ $i->description_en }}</div>@endif
                @if($i->warranty_months)<div class="muted">ضمان {{ $i->warranty_months }} شهراً / {{ $i->warranty_months }}-month warranty</div>@endif
            </td>
            <td class="num">{{ rtrim(rtrim($i->quantity, '0'), '.') }} {{ $i->unit }}</td>
            <td class="num">{{ $money($i->unit_price) }}</td>
            <td class="num">{{ (float) $i->discount_amount ? $money($i->discount_amount) : '—' }}</td>
            <td class="num">{{ rtrim(rtrim($i->vat_rate, '0'), '.') }}</td>
            <td class="num">{{ $money($i->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="width:45%; margin-top:8px; margin-right:55%">
    <tr><td>المجموع / Subtotal</td><td class="num">{{ $money($q->subtotal) }}</td></tr>
    @if((float) $q->discount_total)<tr><td>الخصم / Discount</td><td class="num">− {{ $money($q->discount_total) }}</td></tr>@endif
    <tr><td>ضريبة القيمة المضافة / VAT</td><td class="num">{{ $money($q->vat_total) }}</td></tr>
    <tr class="grand"><td>الإجمالي / Total (OMR)</td><td class="num">{{ $money($q->total) }}</td></tr>
</table>

@foreach(['delivery_terms' => 'شروط التسليم / Delivery', 'warranty_terms' => 'الضمان / Warranty', 'payment_terms' => 'الدفع / Payment', 'estimated_duration' => 'المدة المتوقعة / Duration'] as $field => $label)
    @if(filled($q->{$field}))
        <div style="margin-top:8px"><b>{{ $label }}:</b> {!! nl2br(e($q->{$field})) !!}</div>
    @endif
@endforeach

<p class="muted" style="margin-top:14px">
    الأسعار بالريال العُماني قبل الضريبة، والضريبة محسوبة لكل سطر. العرض صالح حتى التاريخ المذكور أعلاه.<br>
    <span class="en">Prices in Omani rials excluding VAT; VAT is calculated per line. This quote is valid until the date shown above.</span>
</p>
</body>
</html>
