@php
    $money = fn ($v) => number_format((float) $v, 3);
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $seller = $inv->seller_snapshot ?? [];
    $buyer = $inv->buyer_snapshot ?? [];
    $source = match (true) {
        (bool) $inv->sales_order_id => ['طلب قطع', 'Parts order', $inv->salesOrder?->number],
        (bool) $inv->quotation_id => ['عرض سعر', 'Quotation', $inv->quotation?->number],
        default => ['طلب صيانة', 'Service request', null],
    };
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
    .box { border: 1px solid #ddd3c2; padding: 6px; vertical-align: top; }
    .void { color: #c5221f; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%">
            <h1>{{ $seller['name_ar'] ?? '' }}</h1>
            <div class="en">{{ $seller['name_en'] ?? '' }}</div>
            @if(!empty($seller['address_ar']))<div class="muted">{{ $seller['address_ar'] }}</div>@endif
            @if(!empty($seller['cr_number']))<div class="muted">السجل التجاري / CR: <bdo dir="ltr">{{ $seller['cr_number'] }}</bdo></div>@endif
            @if(!empty($seller['vat_number']))<div class="muted">الرقم الضريبي / VATIN: <bdo dir="ltr">{{ $seller['vat_number'] }}</bdo></div>@endif
            @if(!empty($seller['phone']))<div class="muted"><bdo dir="ltr">{{ $seller['phone'] }}</bdo></div>@endif
        </td>
        <td style="width:45%; text-align:left">
            @if($note)
                <h1>إشعار دائن · <span class="en">Credit note</span></h1>
                <div class="num"><b>{{ $note->number }}</b></div>
                <div>الفاتورة / Invoice: <bdo dir="ltr">{{ $inv->number }}</bdo></div>
                <div>التاريخ / Date: <bdo dir="ltr">{{ $note->issued_at->timezone('Asia/Muscat')->format('Y-m-d') }}</bdo></div>
            @else
                <h1>فاتورة ضريبية · <span class="en">Tax invoice</span></h1>
                <div class="num"><b>{{ $inv->number }}</b></div>
                <div>التاريخ / Date: <bdo dir="ltr">{{ $inv->issue_date?->format('Y-m-d') }}</bdo></div>
                <div>الاستحقاق / Due: <bdo dir="ltr">{{ $inv->due_date?->format('Y-m-d') }}</bdo></div>
                @if($source[2])<div>{{ $source[0] }} / {{ $source[1] }}: <bdo dir="ltr">{{ $source[2] }}</bdo></div>@endif
            @endif
        </td>
    </tr>
</table>

<table style="margin-top:8px">
    <tr>
        <td class="box" style="width:60%">
            <div class="muted">العميل / Bill to</div>
            <b>{{ $buyer['name'] ?? '' }}</b>
            @if(!empty($buyer['cr_number']))<div>السجل التجاري / CR: <bdo dir="ltr">{{ $buyer['cr_number'] }}</bdo></div>@endif
            @if(!empty($buyer['vat_number']))<div>الرقم الضريبي / VATIN: <bdo dir="ltr">{{ $buyer['vat_number'] }}</bdo></div>@endif
            @if(!empty($buyer['contact']) && $buyer['contact'] !== ($buyer['name'] ?? null))<div>{{ $buyer['contact'] }}</div>@endif
            @if(!empty($buyer['phone']))<div><bdo dir="ltr">{{ $buyer['phone'] }}</bdo></div>@endif
        </td>
        <td class="box" style="width:40%">
            <div class="muted">العملة / Currency</div>
            <b>ريال عُماني / OMR</b>
            @if($inv->status === 'void')<div class="void">ملغاة / VOID</div>@endif
        </td>
    </tr>
</table>

@if($note)
    <div class="box" style="margin-top:10px"><b>السبب / Reason:</b> {!! nl2br(e($note->reason)) !!}</div>
    <table class="totals" style="width:62%; margin-top:8px; margin-right:38%">
        <tr><td>المبلغ قبل الضريبة / Amount excl. VAT</td><td class="num">{{ $money($note->subtotal) }}</td></tr>
        <tr><td>ضريبة القيمة المضافة / VAT</td><td class="num">{{ $money($note->vat_total) }}</td></tr>
        <tr class="grand"><td>الإجمالي الدائن / Total credit (OMR)&lrm;</td><td class="num">{{ $money($note->total) }}</td></tr>
    </table>
@else
    <table class="items" style="margin-top:10px">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th>البيان / Description</th>
                <th style="width:9%">الكمية<br>Qty</th>
                <th style="width:11%">السعر<br>Unit price</th>
                <th style="width:9%">الخصم<br><span style="font-size:7pt">Discount</span></th>
                <th style="width:11%">قبل الضريبة<br>Net</th>
                <th style="width:10%">الضريبة<br>VAT</th>
                <th style="width:12%">الإجمالي<br>Total</th>
            </tr>
        </thead>
        <tbody>
        @foreach($inv->items as $i)
            <tr>
                <td class="num">{{ $i->line_no }}</td>
                <td>
                    {{ $i->description_ar }}
                    @if($i->description_en !== $i->description_ar)<div class="muted"><bdo dir="ltr">{{ $i->description_en }}</bdo></div>@endif
                    @if($i->warranty_until)<div class="muted">الضمان حتى / Warranty until <bdo dir="ltr">{{ $i->warranty_until->format('Y-m-d') }}</bdo></div>@endif
                </td>
                <td class="num">{{ $qty($i->quantity) }}</td>
                <td class="num">{{ $money($i->unit_price) }}</td>
                <td class="num">{{ (float) $i->discount_amount ? $money($i->discount_amount) : '—' }}</td>
                <td class="num">{{ $money($i->line_subtotal) }}</td>
                <td class="num">{{ $money($i->line_vat) }} <span class="muted">({{ $qty($i->vat_rate) }}%)</span></td>
                <td class="num">{{ $money($i->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="width:62%; margin-top:8px; margin-right:38%">
        <tr><td>المجموع / Subtotal</td><td class="num">{{ $money($inv->subtotal) }}</td></tr>
        @if((float) $inv->discount_total)<tr><td>الخصم / Discount</td><td class="num">− {{ $money($inv->discount_total) }}</td></tr>@endif
        <tr><td>ضريبة القيمة المضافة / VAT</td><td class="num">{{ $money($inv->vat_total) }}</td></tr>
        <tr class="grand"><td>الإجمالي / Total (OMR)&lrm;</td><td class="num">{{ $money($inv->total) }}</td></tr>
        <tr><td>المدفوع / Paid</td><td class="num">{{ $money($inv->amount_paid) }}</td></tr>
        <tr><td>المتبقي / Balance due</td><td class="num">{{ $money(bcsub((string) $inv->total, (string) $inv->amount_paid, 3)) }}</td></tr>
    </table>
@endif

<div class="muted" style="margin-top:14px">المبالغ بالريال العُماني بثلاث خانات عشرية، والضريبة محسوبة لكل سطر ومقرّبة لأقرب بيسة.</div>
<div class="en muted" dir="ltr">Amounts in Omani rials to three decimals; VAT is calculated per line and rounded to the nearest baisa.</div>
</body>
</html>
