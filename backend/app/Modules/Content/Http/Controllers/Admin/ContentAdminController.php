<?php

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\Faq;
use App\Modules\Content\Models\Page;
use App\Modules\Content\Support\SafeMarkdown;
use App\Modules\Platform\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A-14 المحتوى: الصفحات والأسئلة الشائعة والبنرات (FR-CMS-01، FR-CMS-03، FR-CMS-04، US-42) */
class ContentAdminController
{
    public const FAQ_SECTIONS = ['equipment', 'parts', 'service'];

    public const PLACEMENTS = ['web_home', 'app_home', 'parts', 'service'];

    // ---------- الصفحات ----------
    public function pages(): JsonResponse
    {
        $pages = Page::query()->leftJoin('users as u', 'u.id', '=', 'pages.updated_by')
            ->orderBy('slug')->get(['pages.id', 'slug', 'title_ar', 'title_en', 'pages.status', 'pages.updated_at', 'u.full_name as updated_by_name']);

        return response()->json(['data' => $pages->map(fn ($p) => [
            'id' => $p->id, 'slug' => $p->slug, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'status' => $p->status,
            'updated_at' => $p->updated_at?->toIso8601ZuluString(), 'updated_by' => $p->updated_by_name,
        ])->values()]);
    }

    public function page(string $id): JsonResponse
    {
        return response()->json(['data' => $this->pageDetail($this->findPage($id))]);
    }

    public function savePage(Request $request, ?string $id = null): JsonResponse
    {
        $page = $id ? $this->findPage($id) : new Page;
        $data = $request->validate([
            // المعرّف ثابت بعد الإنشاء: روابط الموقع تعتمد عليه
            'slug' => [$id ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'body_ar' => ['required', 'string', 'max:100000'],
            'body_en' => ['nullable', 'string', 'max:100000'],
            'seo_title_ar' => ['nullable', 'string', 'max:255'],
            'seo_title_en' => ['nullable', 'string', 'max:255'],
            'seo_description_ar' => ['nullable', 'string', 'max:500'],
            'seo_description_en' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        $data['body_ar'] = SafeMarkdown::clean($data['body_ar']);
        $data['body_en'] = SafeMarkdown::clean($data['body_en'] ?? '');

        // NFR-14: لا نشر دون المحتوى باللغتين
        if ($data['status'] === 'published') {
            $missing = array_filter(['body_ar' => $data['body_ar'], 'body_en' => $data['body_en']], fn ($v) => $v === '');
            if ($missing) {
                throw ValidationException::withMessages(array_map(fn () => [__('validation.required_for_publish')], $missing));
            }
        }

        $before = $page->exists ? ['status' => $page->status] : null;
        $page->fill($data + ['updated_by' => $request->user()->id])->save();
        Audit::record($id ? 'page.updated' : 'page.created', 'page', $page->id, $before, ['status' => $page->status, 'slug' => $page->slug]);

        return response()->json(['data' => $this->pageDetail($page->fresh())], $id ? 200 : 201);
    }

    // ---------- الأسئلة الشائعة ----------
    public function faqs(): JsonResponse
    {
        return response()->json(['data' => Faq::query()->orderBy('section')->orderBy('sort_order')
            ->get(['id', 'section', 'question_ar', 'question_en', 'answer_ar', 'answer_en', 'sort_order', 'is_active'])]);
    }

    public function saveFaq(Request $request, ?string $id = null): JsonResponse
    {
        $faq = $id ? Faq::query()->findOrFail($id) : new Faq;
        $data = $request->validate([
            'section' => ['required', Rule::in(self::FAQ_SECTIONS)],
            'question_ar' => ['required', 'string', 'max:1000'],
            'question_en' => ['required', 'string', 'max:1000'],
            'answer_ar' => ['required', 'string', 'max:5000'],
            'answer_en' => ['required', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data = array_map(fn ($v) => is_string($v) ? SafeMarkdown::clean($v) : $v, $data);
        if (! $faq->exists) {
            $data['sort_order'] = (int) Faq::query()->where('section', $data['section'])->max('sort_order') + 1;
        }
        $faq->fill($data)->save();

        return response()->json(['data' => $faq->only(['id', 'section', 'sort_order'])], $id ? 200 : 201);
    }

    /** PUT /admin/faqs/order: الترتيب داخل القسم بترتيب المعرفات المرسلة */
    public function orderFaqs(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['uuid', 'distinct', 'exists:faqs,id'],
        ])['ids'];
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                Faq::query()->whereKey($id)->update(['sort_order' => $i]);
            }
        });

        return $this->faqs();
    }

    // ---------- البنرات ----------
    public function banners(): JsonResponse
    {
        $banners = Banner::query()->with(['imageAr', 'imageEn'])->orderBy('placement')->orderBy('sort_order')->orderByDesc('starts_at')->get();

        return response()->json(['data' => $banners->map(fn (Banner $b) => $this->bannerDetail($b))->values()]);
    }

    public function saveBanner(Request $request, ?string $id = null): JsonResponse
    {
        $banner = $id ? Banner::query()->findOrFail($id) : new Banner;
        $public = Rule::exists('attachments', 'id')->where('visibility', 'public');
        $data = $request->validate([
            'placement' => ['required', Rule::in(self::PLACEMENTS)],
            'image_ar_id' => ['required', 'uuid', $public],
            'image_en_id' => ['required', 'uuid', $public],
            // رابط داخلي يبدأ بـ / أو رابط https فقط
            'link' => ['nullable', 'string', 'max:500', 'regex:#^(/[^/\\\\]|/$|https://)#'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        // التواريخ تصل بإزاحة مسقط (+04:00)، وتُحفظ UTC
        $data['starts_at'] = Carbon::parse($data['starts_at'])->utc();
        $data['ends_at'] = Carbon::parse($data['ends_at'])->utc();
        $banner->fill($data)->save();
        Audit::record($id ? 'banner.updated' : 'banner.created', 'banner', $banner->id, null, [
            'placement' => $banner->placement, 'starts_at' => $banner->starts_at->toIso8601ZuluString(), 'ends_at' => $banner->ends_at->toIso8601ZuluString(),
        ]);

        return response()->json(['data' => $this->bannerDetail($banner->fresh(['imageAr', 'imageEn']))], $id ? 200 : 201);
    }

    private function findPage(string $id): Page
    {
        abort_unless(Str::isUuid($id), 404);

        return Page::query()->findOrFail($id);
    }

    private function pageDetail(Page $p): array
    {
        return [
            ...$p->only(['id', 'slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'seo_title_ar', 'seo_title_en',
                'seo_description_ar', 'seo_description_en', 'status']),
            'updated_at' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function bannerDetail(Banner $b): array
    {
        $now = now();

        return [
            ...$b->only(['id', 'placement', 'image_ar_id', 'image_en_id', 'link', 'sort_order', 'is_active']),
            'image_ar_url' => $b->imageAr?->url(),
            'image_en_url' => $b->imageEn?->url(),
            'starts_at' => $b->starts_at->toIso8601ZuluString(),
            'ends_at' => $b->ends_at->toIso8601ZuluString(),
            // للعرض في A-14: هل يظهر الآن؟
            'state' => ! $b->is_active ? 'inactive' : ($b->ends_at <= $now ? 'ended' : ($b->starts_at > $now ? 'scheduled' : 'live')),
        ];
    }
}
