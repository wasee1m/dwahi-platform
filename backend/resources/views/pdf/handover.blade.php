@php
    $customer = $sr->organization?->name ?? $sr->customer?->full_name;
    $subject = $sr->equipment
        ? $sr->equipment->displayName()
        : __('service.components.'.$sr->component_type, [], 'ar').' / '.__('service.components.'.$sr->component_type, [], 'en');
    $at = \Illuminate\Support\Carbon::parse($handover->handed_over_at, 'UTC')->timezone('Asia/Muscat');
@endphp
<html dir="rtl">
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; color: #231f1a; }
    .muted { color: #6b6257; }
    h1 { font-size: 16pt; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .box { border: 1px solid #ddd3c2; padding: 8px; vertical-align: top; }
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
        </td>
        <td style="width:45%; text-align:left">
            <h1>إيصال تسليم · <bdo dir="ltr">Handover receipt</bdo></h1>
            <div>طلب الصيانة / Service request: <bdo dir="ltr">{{ $sr->number }}</bdo></div>
            @if($sr->intake)<div>بطاقة الاستلام / Receiving card: <bdo dir="ltr">{{ $sr->intake->number }}</bdo></div>@endif
            <div>التاريخ / Date: <bdo dir="ltr">{{ $at->format('Y-m-d H:i') }}</bdo></div>
        </td>
    </tr>
</table>

<table style="margin-top:10px">
    <tr><th>العميل / Customer</th><td class="v"><b>{{ $customer }}</b></td></tr>
    <tr><th>المعدة أو المكوّن / Item</th><td class="v">{{ $subject }}@if($sr->equipment?->serial_number) · <bdo dir="ltr">{{ $sr->equipment->serial_number }}</bdo>@endif</td></tr>
    <tr><th>حالة الطلب / Request</th><td class="v">{{ $sr->status === 'closed' ? 'أُنجز الإصلاح / Repaired' : 'دون إصلاح (ملغى أو مرفوض) / Returned without repair' }}</td></tr>
    @if($invoice)
        <tr><th>الفاتورة / Invoice</th><td class="v"><bdo dir="ltr">{{ $invoice->number }} · {{ number_format((float) $invoice->total, 3) }} OMR</bdo></td></tr>
    @endif
    @if($payment)
        <tr><th>الدفعة / Payment</th><td class="v"><bdo dir="ltr">{{ number_format((float) $payment->amount, 3) }} OMR · {{ $payment->method }}</bdo></td></tr>
    @endif
</table>

<table style="margin-top:14px">
    <tr>
        <td class="box" style="width:50%">
            <div class="muted">استلمها / Received by</div>
            <b>{{ $handover->receiver_name }}</b>
            @if($handover->receiver_phone)<div><bdo dir="ltr">{{ $handover->receiver_phone }}</bdo></div>@endif
            @if($signature)<div style="margin-top:6px"><img src="{{ $signature }}" style="height:60px" /></div>@endif
        </td>
        <td class="box" style="width:50%">
            <div class="muted">سلّمها / Handed over by</div>
            <b>{{ $staff->full_name }}</b>
        </td>
    </tr>
</table>

<div class="muted" style="margin-top:14px">استلم العميل المعدة أو المكوّن بحالته الموضحة في تقرير الخدمة.</div>
<div class="muted" dir="ltr">The customer received the item in the condition described in the service report.</div>
</body>
</html>
