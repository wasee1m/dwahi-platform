<?php

namespace App\Modules\Notifications\Support;

/**
 * أحداث الإشعارات المبنية حتى الآن (01 §8): الفئة لتفضيلات المستخدم (FR-NOT-03)، والقنوات، والإلزامية.
 * in_app دائماً (مركز الإشعارات C-19)، والقنوات الأخرى تُرسل في الطابور. الإلزامي لا تعطله التفضيلات.
 * N-12 و N-14 و N-15 مع الإنجاز والتسليم (S9).
 */
final class EventCatalog
{
    public const CHANNELS = ['push', 'email', 'sms'];

    /** فئات التفضيلات (03 §11). فئة account إلزامية كلها فلا تظهر في المصفوفة. */
    public const CATEGORIES = ['quotes', 'orders', 'service', 'messages', 'marketing'];

    /** أحداث للموظف وحده، فلا تدخل مصفوفة العميل */
    private const STAFF_ONLY = ['N-03', 'N-23', 'N-08', 'N-08-urgent', 'N-09', 'N-18', 'N-13'];

    /**
     * أحداث العميل في الوحدات القادمة كما في 01 §8، لتعرض C-20 الإلزامي صحيحاً من الآن.
     * ينتقل كل حدث إلى EVENTS مع وحدته.
     */
    private const UPCOMING = [
        'N-12' => ['category' => 'service', 'channels' => ['push', 'sms'], 'mandatory' => true],
        'N-14' => ['category' => 'service', 'channels' => ['push', 'email'], 'mandatory' => true],
        'N-15' => ['category' => 'service', 'channels' => ['push'], 'mandatory' => false],
    ];

    /** @var array<string, array{category: string, channels: list<string>, mandatory: bool}> */
    public const EVENTS = [
        'N-01' => ['category' => 'quotes', 'channels' => ['email'], 'mandatory' => true],            // استلام طلب عرض سعر
        'N-02' => ['category' => 'quotes', 'channels' => ['push', 'email', 'sms'], 'mandatory' => true], // عرض جديد أو محدث
        'N-03' => ['category' => 'quotes', 'channels' => ['email'], 'mandatory' => true],            // رد العميل
        'N-04' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => false],   // ينتهي خلال 48 ساعة
        'N-17' => ['category' => 'messages', 'channels' => ['push'], 'mandatory' => false],          // رسالة جديدة (+بريد J-10)
        'N-19' => ['category' => 'account', 'channels' => ['sms', 'email'], 'mandatory' => true],     // دعوة لحساب شركة
        'N-05' => ['category' => 'orders', 'channels' => ['push', 'email', 'sms'], 'mandatory' => true],   // تأكيد الطلب
        'N-06' => ['category' => 'orders', 'channels' => ['push', 'sms'], 'mandatory' => true],   // جاهز / خرج للتوصيل / تم التسليم
        'N-07' => ['category' => 'orders', 'channels' => ['push', 'sms'], 'mandatory' => true],   // إلغاء الطلب وتذكير الاستلام
        'N-16' => ['category' => 'orders', 'channels' => ['email'], 'mandatory' => true],   // إصدار فاتورة
        'N-26' => ['category' => 'orders', 'channels' => ['push', 'sms'], 'mandatory' => true],   // تعذر التسليم
        'N-27' => ['category' => 'orders', 'channels' => ['push'], 'mandatory' => true],   // قرار الإرجاع (وللموظف: طلب جديد)
        'N-23' => ['category' => 'orders', 'channels' => ['email'], 'mandatory' => true],            // طلب قطع جديد لموظفي الفرع
        'N-10' => ['category' => 'service', 'channels' => ['push', 'email', 'sms'], 'mandatory' => true],   // تقدير صيانة بانتظار الموافقة
        'N-11' => ['category' => 'service', 'channels' => ['push', 'sms'], 'mandatory' => true],   // موعد العمل في الورشة
        'N-24' => ['category' => 'service', 'channels' => ['push', 'email'], 'mandatory' => true],   // اعتماد آلي ضمن حد الموافقة
        'N-29' => ['category' => 'service', 'channels' => ['push', 'sms'], 'mandatory' => true],   // إلغاء موعد أو أمر عمل
        'N-32' => ['category' => 'service', 'channels' => ['push', 'email', 'sms'], 'mandatory' => true],   // استلام المعدة مع الإيصال
        'N-08' => ['category' => 'service', 'channels' => ['email'], 'mandatory' => true],            // طلب صيانة جديد للمنسقين
        'N-08-urgent' => ['category' => 'service', 'channels' => ['email', 'sms'], 'mandatory' => true], // والعاجل برسالة SMS
        'N-09' => ['category' => 'service', 'channels' => ['email'], 'mandatory' => true],            // تجاوز مهلة الفرز
        'N-18' => ['category' => 'service', 'channels' => ['push'], 'mandatory' => true],             // مهمة جديدة أو معدلة للفني
        'N-13' => ['category' => 'service', 'channels' => ['push'], 'mandatory' => true],             // أمر عمل متوقف، للمنسق
        'N-25' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => true],    // خسارة الطلب مع السبب
        'N-31' => ['category' => 'quotes', 'channels' => ['push', 'email'], 'mandatory' => true],    // طلب اعتماد من عضو
        'N-33' => ['category' => 'account', 'channels' => ['email'], 'mandatory' => true],           // نسخة البيانات جاهزة
    ];

    /** @return 'editable'|'locked'|'unused' هل لمفتاح الفئة والقناة أثر؟ */
    public static function state(string $category, string $channel): string
    {
        if ($category === 'marketing') {
            return 'editable';
        }
        $events = self::customerEvents($category, $channel);

        return match (true) {
            $events === [] => 'unused',
            array_filter($events, fn ($e) => ! $e['mandatory']) === [] => 'locked',
            default => 'editable',
        };
    }

    public static function hasMandatory(string $category, string $channel): bool
    {
        return array_filter(self::customerEvents($category, $channel), fn ($e) => $e['mandatory']) !== [];
    }

    /** @return array<string, array{category: string, channels: list<string>, mandatory: bool}> */
    private static function customerEvents(string $category, string $channel): array
    {
        return array_filter(
            array_diff_key(self::EVENTS + self::UPCOMING, array_flip(self::STAFF_ONLY)),
            fn ($e) => $e['category'] === $category && in_array($channel, $e['channels'], true),
        );
    }
}
