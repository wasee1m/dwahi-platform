@php
    $customer = $sr->organization?->name ?? $sr->customer?->full_name;
    $subject = $sr->equipment
        ? $sr->equipment->displayName()
        : __('service.components.'.$sr->component_type, [], 'ar').' / '.__('service.components.'.$sr->component_type, [], 'en');
    $lead = $wo->technicians->firstWhere('pivot.role', 'lead');
@endphp
<html dir="rtl">
<head>
<style>
    body { font-family: dejavusans; font-size: 10pt; color: #231f1a; }
    .muted { color: #6b6257; }
    h1 { font-size: 16pt; margin: 0; }
    h2 { font-size: 12pt; margin: 12px 0 4px; }
    table { border-collapse: collapse; width: 100%; }
    th { text-align: right; color: #6b6257; font-weight: normal; width: 30%; padding: 4px; vertical-align: top; }
    td.v { padding: 4px; }
    .grid td { border: 1px solid #ddd3c2; padding: 4px; }
    .pass { color: #2f6b3a; font-weight: bold; }
    .fail { color: #a5361f; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:55%">
            <h1>{{ $company['name_ar'] ?? '' }}</h1>
            <div><bdo dir="ltr">{{ $company['name_en'] ?? '' }}</bdo></div>
            @if(!empty($company['phone']))<div class="muted"><bdo dir="ltr">{{ $company['phone'] }}</bdo></div>@endif
        </td>
        <td style="width:45%; text-align:left">
            <h1>تقرير الخدمة · <bdo dir="ltr">Service report</bdo></h1>
            <div>أمر العمل / Work order: <bdo dir="ltr">{{ $wo->number }}</bdo></div>
            <div>طلب الصيانة / Service request: <bdo dir="ltr">{{ $sr->number }}</bdo></div>
            <div>التاريخ / Date: <bdo dir="ltr">{{ ($wo->verified_at ?? now())->timezone('Asia/Muscat')->format('Y-m-d') }}</bdo></div>
        </td>
    </tr>
</table>

<table style="margin-top:10px">
    <tr><th>العميل / Customer</th><td class="v"><b>{{ $customer }}</b></td></tr>
    <tr><th>المعدة أو المكوّن / Item</th><td class="v">{{ $subject }}@if($sr->equipment?->serial_number) · <bdo dir="ltr">{{ $sr->equipment->serial_number }}</bdo>@endif</td></tr>
    @if($report?->hour_meter_reading !== null)<tr><th>عداد الساعات / Hour meter</th><td class="v"><bdo dir="ltr">{{ number_format($report->hour_meter_reading) }} h</bdo></td></tr>@endif
    <tr><th>الأعطال المكتشفة / Findings</th><td class="v">{!! nl2br(e($report?->findings ?? '—')) !!}</td></tr>
    @if(filled($report?->actions_taken))<tr><th>الأعمال المنفذة / Work done</th><td class="v">{!! nl2br(e($report->actions_taken)) !!}</td></tr>@endif
    @if($report?->recommendations)<tr><th>التوصيات / Recommendations</th><td class="v">{!! nl2br(e($report->recommendations)) !!}</td></tr>@endif
</table>

<h2>القطع المستخدمة / Parts used</h2>
@if($parts->isEmpty())
    <div class="muted">لا يوجد / None</div>
@else
    <table class="grid">
        @foreach($parts as $p)
            <tr>
                <td>@if($p->part_number)<bdo dir="ltr">{{ $p->part_number }}</bdo> · {{ $p->name_ar }}@else{{ $p->description }}@endif</td>
                <td style="width:15%"><bdo dir="ltr">{{ rtrim(rtrim((string) $p->quantity, '0'), '.') }}</bdo></td>
                <td style="width:25%">{{ ['store' => 'من المخزن / Store', 'customer_supplied' => 'من العميل / Customer', 'purchased' => 'مشتراة / Purchased'][$p->source] ?? $p->source }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if($tests->isNotEmpty())
    <h2>نتيجة الاختبار / Test result</h2>
    @foreach($tests as $t)
        <div><b>{{ $t->name_ar }}</b> · <bdo dir="ltr">{{ $t->name_en }}</bdo> ·
            <span class="{{ $t->result }}">{{ $t->result === 'pass' ? 'ناجح / Pass' : 'فاشل / Fail' }}</span></div>
        <table class="grid">
            @foreach($t->fields as $f)
                @php $v = $t->values[$f['key']] ?? null; @endphp
                <tr>
                    <td>{{ $f['label_ar'] }} / <bdo dir="ltr">{{ $f['label_en'] }}</bdo></td>
                    <td style="width:30%"><bdo dir="ltr">@if(($f['type'] ?? 'number') === 'boolean'){{ $v === true ? 'Yes' : ($v === false ? 'No' : '—') }}@else{{ $v ?? '—' }} {{ $f['unit'] ?? '' }}@endif</bdo></td>
                </tr>
            @endforeach
        </table>
        @if($t->notes)<div class="muted">{{ $t->notes }}</div>@endif
    @endforeach
@endif

@foreach(['before' => 'قبل / Before', 'after' => 'بعد / After'] as $phase => $label)
    @if(!empty($$phase))
        <h2>{{ $label }}</h2>
        <table><tr>@foreach($$phase as $img)<td style="width:25%"><img src="{{ $img }}" style="width:160px" /></td>@endforeach</tr></table>
    @endif
@endforeach

<table style="margin-top:14px">
    <tr>
        <td style="width:50%"><div class="muted">الفني / Technician</div><b>{{ $lead?->full_name }}</b></td>
        <td style="width:50%"><div class="muted">اعتمده / Verified by</div><b>{{ $wo->verifier?->full_name }}</b></td>
    </tr>
</table>
</body>
</html>
