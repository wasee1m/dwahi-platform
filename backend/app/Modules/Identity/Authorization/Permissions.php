<?php

namespace App\Modules\Identity\Authorization;

/**
 * خريطة الأدوار والصلاحيات في مكان واحد (04 §5)، مطابقة لمصفوفة 01 §3.2 ومختبرة.
 * صلاحيات العميل (أفراد وشركات) ليست هنا: تحكمها قواعد الرؤية (03 §13.1).
 */
final class Permissions
{
    public const ROLE_ABILITIES = [
        'admin' => ['*'],
        'sales' => ['rfq.create_on_behalf', 'quotation.send', 'customers.view', 'leads.manage', 'reports.own', 'catalog.manage'],
        'service_coordinator' => [
            'rfq.create_on_behalf', 'quotation.send', 'service.triage', 'workorder.verify', 'workshop.handover',
            'devices.manage', 'customers.view', 'leads.manage', 'reports.own',
        ],
        'technician' => ['workorder.execute'],
        'parts_staff' => ['parts.manage', 'orders.deliver', 'reports.own'],
        'accountant' => ['invoices.manage', 'refunds.manage', 'reports.view'],
        'content_editor' => ['content.manage'],
    ];

    /** صلاحيات للمدير فقط في 01 §3.2 */
    public const ADMIN_ONLY = ['staff.manage', 'settings.manage', 'audit.view', 'customers.suspend'];

    /** @param list<string> $roles */
    public static function allows(array $roles, string $ability): bool
    {
        foreach ($roles as $role) {
            $abilities = self::ROLE_ABILITIES[$role] ?? [];
            if (in_array('*', $abilities, true) || in_array($ability, $abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> كل الصلاحيات المعرّفة */
    public static function all(): array
    {
        return array_values(array_unique([
            ...self::ADMIN_ONLY,
            ...array_merge(...array_values(array_map(fn ($a) => array_diff($a, ['*']), self::ROLE_ABILITIES))),
        ]));
    }

    /** @param list<string> $roles @return list<string> */
    public static function forRoles(array $roles): array
    {
        return array_values(array_filter(self::all(), fn ($a) => self::allows($roles, $a)));
    }
}
