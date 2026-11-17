<?php

namespace Database\Seeders;

use App\Modules\Content\Models\Faq;
use App\Modules\Content\Models\Page;
use Illuminate\Database\Seeder;

/**
 * محتوى مبدئي للموقع (FR-CMS-01، FR-CMS-03). "من نحن" من معلومات الشركة في 01 §1.1،
 * والأسئلة الشائعة من قواعد العمل الحالية. الصفحات القانونية مسودات حتى المراجعة القانونية،
 * وكل النصوص تحتاج مراجعة الشركة قبل الإطلاق.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $pending = '> نص مبدئي. يُكتب النص النهائي بعد المراجعة القانونية، ويُنشر من لوحة الإدارة.';
        $pendingEn = '> Draft. The final text follows legal review and is published from the admin panel.';

        $pages = [
            'about' => [
                'من نحن', 'About us',
                <<<'MD'
## ورشة تعرف آلتك منذ 1995

بدأت ضواحي البادية بإصلاح الجرارات الزراعية ومحركات الآبار، ثم توسعت لتخدم كل أنواع الآليات الثقيلة والشاحنات.

نعمل اليوم من ورشتنا في **المنطقة الصناعية بالعوهي في صحار**، ونتخصص في:

- إصلاح محركات الديزل وتجديدها وتبديلها
- الأنظمة الهيدروليكية: المضخات والسلندرات بأنواعها
- ناقل الحركة وإصلاح هياكله
- توريد وتركيب أنظمة القلابات على الشاحنات

ونبيع المعدات الثقيلة الجديدة والمستعملة، والمكوّنات المجددة، وقطع الغيار.
MD,
                <<<'MD'
## A workshop that has known your machine since 1995

DWAHI-ALBADIA started out repairing farm tractors and well engines, and grew to serve every kind of heavy machine and truck.

Today we work from our workshop in **Al-Awhi Industrial Area, Sohar**, specialising in:

- Diesel engine repair, rebuild and repowering
- Hydraulic systems: pumps and cylinders of every type
- Transmissions, including housing repairs
- Supply and installation of tipper systems on trucks

We also sell new and used heavy equipment, rebuilt components and spare parts.
MD,
            ],
            'terms' => ['الشروط والأحكام', 'Terms and conditions', $pending, $pendingEn],
            'privacy' => ['سياسة الخصوصية', 'Privacy policy', $pending, $pendingEn],
            'returns' => ['سياسة الإرجاع', 'Returns policy', $pending, $pendingEn],
            'warranty' => ['الضمان', 'Warranty', $pending, $pendingEn],
        ];

        foreach ($pages as $slug => [$titleAr, $titleEn, $bodyAr, $bodyEn]) {
            Page::query()->firstOrCreate(['slug' => $slug], [
                'title_ar' => $titleAr, 'title_en' => $titleEn,
                'body_ar' => $bodyAr, 'body_en' => $bodyEn,
                'status' => 'published',
            ]);
        }

        if (Faq::query()->exists()) {
            return;
        }

        $faqs = [
            ['service', 'هل تقدمون الصيانة في موقع العميل؟', 'Do you offer on-site service?',
                'لا. نعمل في الورشة فقط بصناعية العوهي في صحار. أحضر المعدة أو المكوّن ونستلمه ببطاقة استلام.',
                'No. We work in our workshop in Al-Awhi Industrial Area, Sohar only. Bring the machine or component and we log it with an intake card.'],
            ['service', 'هل أعرف التكلفة قبل الإصلاح؟', 'Will I know the cost before the repair?',
                'نعم. نرسل لك تقديراً ولا نبدأ الإصلاح قبل موافقتك عليه.',
                'Yes. We send you an estimate and do not start the repair until you approve it.'],
            ['parts', 'كيف أدفع ثمن القطع؟', 'How do I pay for parts?',
                'عند الاستلام من الفرع أو عند التوصيل: نقداً، أو بالبطاقة عبر جهاز نقاط البيع، أو بتحويل بنكي.',
                'On collection from the branch or on delivery: cash, card on our POS terminal, or bank transfer.'],
            ['parts', 'هل على القطع ضمان؟', 'Do parts come with a warranty?',
                'بعض المنتجات عليها ضمان تظهر مدته في صفحتها، مثل أنظمة القلابات الهيدروليكية بضمان سنة.',
                'Some products carry a warranty shown on their page, such as hydraulic tipper systems with a one-year warranty.'],
            ['equipment', 'هل تصدّرون المعدات خارج السلطنة؟', 'Do you export equipment outside Oman?',
                'نعم. اطلب عرض سعر واذكر بلد الوجهة.',
                'Yes. Request a quote and tell us the destination country.'],
            ['equipment', 'كم مدة صلاحية عرض السعر؟', 'How long is a quote valid?',
                'أربعة عشر يوماً من تاريخ إرساله.',
                'Fourteen days from the date it is sent.'],
        ];

        foreach ($faqs as $i => [$section, $qAr, $qEn, $aAr, $aEn]) {
            Faq::query()->create([
                'section' => $section, 'question_ar' => $qAr, 'question_en' => $qEn,
                'answer_ar' => $aAr, 'answer_en' => $aEn, 'sort_order' => $i, 'is_active' => true,
            ]);
        }
    }
}
