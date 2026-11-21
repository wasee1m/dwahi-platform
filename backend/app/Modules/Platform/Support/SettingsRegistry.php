<?php

namespace App\Modules\Platform\Support;

use App\Support\Governorates;

/**
 * كل مفاتيح الإعدادات في مكان واحد (03 §12، FR-ADM-03): التبويب في A-18، ونوع الحقل في الواجهة،
 * وقواعد التحقق، والقيمة الافتراضية. القيم الافتراضية مؤقتة حتى تحددها الشركة (Q-07).
 * القواعد بمسار نسبي: '' للقيمة نفسها، و'x' لحقل داخلها.
 */
final class SettingsRegistry
{
    public const GROUPS = ['general', 'pricing', 'quotes', 'orders', 'service', 'numbering'];

    private const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    /** @return array<string, array{group: string, type: string, rules: array<string, array<int, mixed>>, default: mixed, options?: list<string>, readonly?: bool, rule?: string}> */
    public static function all(): array
    {
        $days = fn (int $max = 365) => ['' => ['required', 'integer', 'min:0', "max:{$max}"]];
        $money = ['' => ['required', 'decimal:0,3', 'min:0', 'max:999999']];
        $time = ['required', 'date_format:H:i'];

        return [
            // عام: بيانات الشركة على الفواتير (FR-PAY-03) والمهل العامة
            'company.profile' => ['group' => 'general', 'type' => 'company', 'rule' => 'FR-PAY-03', 'default' => [
                'name_ar' => 'ضواحي البادية', 'name_en' => 'DWAHI-ALBADIA LLC', 'cr_number' => '', 'vat_number' => '',
                'address_ar' => '', 'address_en' => '', 'phone' => '', 'email' => '',
            ], 'rules' => [
                '' => ['required', 'array:name_ar,name_en,cr_number,vat_number,address_ar,address_en,phone,email'],
                'name_ar' => ['required', 'string', 'max:255'], 'name_en' => ['required', 'string', 'max:255'],
                'cr_number' => ['nullable', 'string', 'max:50'], 'vat_number' => ['nullable', 'string', 'max:50'],
                'address_ar' => ['nullable', 'string', 'max:500'], 'address_en' => ['nullable', 'string', 'max:500'],
                'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            ]],
            'review.window_days' => ['group' => 'general', 'type' => 'days', 'rule' => 'BR-10', 'default' => 30, 'rules' => $days()],
            'account.deletion_grace_days' => ['group' => 'general', 'type' => 'days', 'rule' => 'BR-16', 'default' => 30, 'rules' => $days()],

            // الأسعار والضريبة
            'vat.default_rate' => ['group' => 'pricing', 'type' => 'percent', 'rule' => 'BR-01', 'default' => '5.00',
                'rules' => ['' => ['required', 'decimal:0,2', 'min:0', 'max:100']]],
            'pricing.display' => ['group' => 'pricing', 'type' => 'enum', 'rule' => 'BR-01', 'default' => 'excl_vat', 'options' => ['excl_vat', 'incl_vat'],
                'rules' => ['' => ['required', 'in:excl_vat,incl_vat']]],
            'parts.availability_thresholds' => ['group' => 'pricing', 'type' => 'thresholds', 'rule' => 'BR-04', 'default' => ['limited' => 1, 'available' => 5],
                'rules' => ['' => ['required', 'array:limited,available'], 'limited' => ['required', 'integer', 'min:1'], 'available' => ['required', 'integer', 'gt:value.limited']]],

            // العروض: تُطبق على العروض الجديدة فقط (US-40)
            'quote.validity_days' => ['group' => 'quotes', 'type' => 'days', 'rule' => 'BR-02', 'default' => 14, 'rules' => ['' => ['required', 'integer', 'min:1', 'max:90']]],

            // الطلبات والتوصيل
            'order.pickup_hold_working_days' => ['group' => 'orders', 'type' => 'days', 'rule' => 'BR-05', 'default' => 3, 'rules' => $days(30)],
            'order.max_open_unpaid_per_customer' => ['group' => 'orders', 'type' => 'count', 'rule' => 'BR-05', 'default' => 3, 'rules' => ['' => ['required', 'integer', 'min:1', 'max:100']]],
            'order.phone_confirm_above_amount' => ['group' => 'orders', 'type' => 'money', 'rule' => 'BR-18', 'default' => '100.000', 'rules' => $money],
            'returns.window_days' => ['group' => 'orders', 'type' => 'days', 'rule' => 'BR-12', 'default' => 14, 'rules' => $days(90)],
            // رسوم التوصيل لكل محافظة، والقيمة الفارغة تعني لا توصيل لها (FR-ORD-02)
            'delivery.fee_by_governorate' => ['group' => 'orders', 'type' => 'governorate_fees', 'rule' => 'FR-ORD-02',
                'default' => array_fill_keys(Governorates::ALL, null),
                'rules' => ['' => ['required', 'array:'.implode(',', Governorates::ALL)], '*' => ['nullable', 'decimal:0,3', 'min:0', 'max:9999']]],

            // الصيانة
            'fees.inspection' => ['group' => 'service', 'type' => 'money', 'rule' => 'BR-11', 'default' => null,
                'rules' => ['' => ['nullable', 'decimal:0,3', 'min:0', 'max:99999']]],
            'sla.triage' => ['group' => 'service', 'type' => 'sla', 'rule' => 'BR-09', 'default' => ['normal_working_hours' => 4, 'urgent_hours' => 1],
                'rules' => ['' => ['required', 'array:normal_working_hours,urgent_hours'],
                    'normal_working_hours' => ['required', 'integer', 'min:1', 'max:200'], 'urgent_hours' => ['required', 'integer', 'min:1', 'max:48']]],
            'business_hours' => ['group' => 'service', 'type' => 'business_hours', 'rule' => 'BR-09',
                'default' => ['days' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'start' => '08:00', 'end' => '17:00'],
                'rules' => ['' => ['required', 'array:days,start,end'], 'days' => ['required', 'array', 'min:1'], 'days.*' => ['in:'.implode(',', self::DAYS), 'distinct'],
                    'start' => $time, 'end' => [...$time, 'after:value.start']]],
            'service.release_requires_payment' => ['group' => 'service', 'type' => 'bool', 'rule' => 'BR-20', 'default' => true, 'rules' => ['' => ['required', 'boolean']]],
            'service.collection_reminder_days' => ['group' => 'service', 'type' => 'days', 'rule' => 'BR-20', 'default' => 3, 'rules' => $days(60)],

            // الترقيم: للعرض فقط. تغيير البادئة بعد الإصدار يكسر تسلسل المستندات (BR-14)
            'numbering.prefixes' => ['group' => 'numbering', 'type' => 'list', 'rule' => 'BR-14', 'readonly' => true,
                'default' => ['RFQ', 'QT', 'SO', 'SR', 'WO', 'INV', 'CN', 'RC'], 'rules' => []],
        ];
    }

    /** قواعد Laravel لمفتاح واحد، بالمسار value و value.x */
    public static function rulesFor(string $key): array
    {
        $out = [];
        foreach (self::all()[$key]['rules'] as $path => $rules) {
            $out[$path === '' ? 'value' : "value.{$path}"] = $rules;
        }

        return $out;
    }

    /** قيمة بعد التحقق بالشكل المحفوظ: الأرقام العشرية نصوص، والأعداد أعداد */
    public static function normalize(string $key, mixed $value): mixed
    {
        $type = self::all()[$key]['type'];

        return match ($type) {
            'days', 'count' => (int) $value,
            'percent' => number_format((float) $value, 2, '.', ''),
            'money' => $value === null || $value === '' ? null : number_format((float) $value, 3, '.', ''),
            'bool' => (bool) $value,
            'thresholds', 'sla' => array_map('intval', $value),
            'governorate_fees' => array_map(fn ($v) => $v === null || $v === '' ? null : number_format((float) $v, 3, '.', ''), $value),
            'business_hours' => ['days' => array_values(array_intersect(self::DAYS, $value['days'])), 'start' => $value['start'], 'end' => $value['end']],
            'company' => array_map(fn ($v) => $v === null ? '' : trim((string) $v), $value),
            default => $value,
        };
    }
}
