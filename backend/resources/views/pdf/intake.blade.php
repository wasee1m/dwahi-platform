@php
    $customer = $sr->organization?->name ?? $sr->customer?->full_name;
    $subject = $sr->equipment
        ? $sr->equipment->displayName()
        : __('service.components.'.$sr->component_type, [], 'ar').' / '.__('service.components.'.$sr->component_type, [], 'en');
@endphp
<html dir="rtl">
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; color: #231f1a; }
    .muted { color: #6b6257; }
    h1 { font-size: 16pt; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .box { border: 1px solid #ddd3c2; padding: 8px; vertical-align: top; }
    .big { font-size: 22pt; font-weight: bold; letter-spacing: 2px; }
    th { text-align: right; color: #6b6257; font-weight: normal; width: 34%; padding: 4px; }
    td.v { padding: 4px; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%">
            <h1>{{ $company['name_ar'] ?? '' }}</h1>
            <div><bdo dir="ltr">{{ $company['name_en'] ?? '' }}</bdo></div>
            @if(!empty($company['address_ar']))<div class="muted">{{ $company['address_ar'] }}</div>@endif
            @if(!empty($company['phone']))<div class="muted"><bdo dir="ltr">{{ $company['phone'] }}</bdo></div>@endif
        </td>
        <td style="width:45%; text-align:left">
            <h1>إيصال استلام · <bdo dir="ltr">Receiving receipt</bdo></h1>
            <div class="big"><bdo dir="ltr">{{ $intake->number }}</bdo></div>
            <div>طلب الصيانة / Service request: <bdo dir="ltr">{{ $sr->number }}</bdo></div>
            <div>التاريخ / Date: <bdo dir="ltr">{{ $intake->received_at->timezone('Asia/Muscat')->format('Y-m-d H:i') }}</bdo></div>
        </td>
    </tr>
</table>

<table style="margin-top:10px">
    <tr><th>العميل / Customer</th><td class="v"><b>{{ $customer }}</b>@if($sr->customer?->phone_e164) · <bdo dir="ltr">{{ $sr->customer->phone_e164 }}</bdo>@endif</td></tr>
    <tr><th>المعدة أو المكوّن / Item</th><td class="v">{{ $subject }}@if($sr->equipment?->serial_number) · <bdo dir="ltr">{{ $sr->equipment->serial_number }}</bdo>@endif</td></tr>
    @if($sr->component_details)<tr><th>تفاصيل المكوّن / Details</th><td class="v">{{ $sr->component_details }}</td></tr>@endif
    @if($sr->customer_equipment_id)
        <tr><th>عداد الساعات / Hour meter</th><td class="v">
            @if($intake->hour_meter_reading !== null)<bdo dir="ltr">{{ number_format($intake->hour_meter_reading) }} h</bdo>@else {{ $intake->hour_meter_missing_reason }} @endif
        </td></tr>
    @endif
    <tr><th>وصف العطل / Reported problem</th><td class="v">{!! nl2br(e($sr->problem_description ?? '—')) !!}</td></tr>
    <tr><th>الحالة عند الاستلام / Condition</th><td class="v">{!! nl2br(e($intake->condition_notes ?? '—')) !!}</td></tr>
    <tr><th>الملحقات / Accessories</th><td class="v">{!! nl2br(e($intake->accessories ?? '—')) !!}</td></tr>
    <tr><th>صور الحالة / Photos</th><td class="v"><bdo dir="ltr">{{ $intake->photos->count() }}</bdo></td></tr>
</table>

<table style="margin-top:14px">
    <tr>
        <td class="box" style="width:50%">
            <div class="muted">سلّمها / Brought by</div>
            <b>{{ $intake->brought_by_name }}</b>
            @if($intake->brought_by_phone)<div><bdo dir="ltr">{{ $intake->brought_by_phone }}</bdo></div>@endif
            @if($signature)<div style="margin-top:6px"><img src="{{ $signature }}" style="height:60px" /></div>@endif
        </td>
        <td class="box" style="width:50%">
            <div class="muted">استلمها / Received by</div>
            <b>{{ $intake->receiver?->full_name }}</b>
        </td>
    </tr>
</table>

<div class="muted" style="margin-top:14px">احتفظ بهذا الإيصال. رقم البطاقة مطبوع على الملصق المثبّت على المعدة أو المكوّن، ولا تُسلَّم بعد الإصلاح إلا بتوقيع المستلم.</div>
<div class="muted" dir="ltr">Keep this receipt. The card number is on the label fixed to the item, and it is handed back only against the receiver's signature.</div>
</body>
</html>
