<?php

namespace App\Modules\Notifications\Support;

/**
 * أحداث الإشعارات المبنية حتى الآن (01 §8): الفئة لتفضيلات المستخدم (FR-NOT-03)، والقنوات، والإلزامية.
 * in_app دائماً (مركز الإشعارات C-19)، والقنوات الأخرى تُرسل في الطابور. الإلزامي لا تعطله التفضيلات.
 * تُضاف الأحداث مع وحداتها (الطلبات S6، الصيانة S7).
 */
final class EventCatalog
{
    public const CHANNELS = ['push', 'email', 'sms'];

    /** @var array<string, array{category: string, channels: list<string>, mandatory: bool}> */
    public const EVENTS = [
        'N-01' => ['category' => 'quotes', 'channels' => ['email'], 'mandatory' => true],            // استلام طلب عرض سعر
        'N-02' => ['category' => 'quotes', 'channels' => ['push', 'email', 'sms'], 'mandatory' => true], // عرض جديد أو محدث
        'N-03' => ['category' => 'quotes', 'channels' => ['email'], 'mandatory' => true],            // رد العميل
        'N-04' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => false],   // ينتهي خلال 48 ساعة
        'N-17' => ['category' => 'messages', 'channels' => ['push'], 'mandatory' => false],          // رسالة جديدة (+بريد J-10)
        'N-25' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => true],    // خسارة الطلب مع السبب
        'N-31' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => true],    // طلب اعتماد من عضو
    ];

    /** التسويق مطفأ افتراضياً (US-23)، والبقية مفعلة حتى يطفئها المستخدم */
    public static function defaultEnabled(string $category): bool
    {
        return $category !== 'marketing';
    }
}
