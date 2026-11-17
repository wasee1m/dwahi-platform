<?php

namespace App\Modules\Content\Http\Controllers;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Content\Models\Banner;
use App\Modules\Content\Models\Faq;
use App\Modules\Content\Models\Page;
use App\Modules\Platform\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** المحتوى العام للموقع والتطبيق (FR-CMS-01، FR-CMS-03، FR-CMS-04، FR-ADM-02) */
class PublicContentController
{
    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['data' => [
            'slug' => $page->slug,
            'title' => $page->localized('title'),
            'body' => $page->localized('body'),
            'seo_title' => $page->localized('seo_title'),
            'seo_description' => $page->localized('seo_description'),
            'updated_at' => $page->updated_at?->toIso8601ZuluString(),
        ]]);
    }

    public function faqs(): JsonResponse
    {
        $faqs = Faq::query()->where('is_active', true)->orderBy('section')->orderBy('sort_order')->get();

        return response()->json(['data' => $faqs->map(fn (Faq $f) => [
            'id' => $f->id,
            'section' => $f->section,
            'question' => $f->localized('question'),
            'answer' => $f->localized('answer'),
        ])->values()]);
    }

    public function banners(Request $request): JsonResponse
    {
        $placement = $request->validate([
            'placement' => ['required', Rule::in(['web_home', 'app_home', 'parts', 'service'])],
        ])['placement'];
        $en = app()->getLocale() === 'en';

        $banners = Banner::query()->where('placement', $placement)->where('is_active', true)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->orderBy('sort_order')->with(['imageAr', 'imageEn'])->get();

        return response()->json(['data' => $banners->map(fn (Banner $b) => [
            'id' => $b->id,
            'image_url' => ($en ? $b->imageEn : $b->imageAr)?->url(),
            'link' => $b->link,
        ])->filter(fn ($b) => $b['image_url'])->values()]);
    }

    public function branches(): JsonResponse
    {
        $branches = Branch::query()->withCoordinates()->where('is_active', true)->orderBy('code')->get();

        return response()->json(['data' => $branches->map(fn (Branch $b) => [
            'code' => $b->code,
            'name' => $b->localized('name'),
            'address' => $b->localized('address'),
            'governorate' => $b->governorate,
            'wilayat' => $b->wilayat,
            'phone' => $b->phone,
            'whatsapp_sales' => $b->whatsapp_sales,
            'whatsapp_service' => $b->whatsapp_service,
            'whatsapp_parts' => $b->whatsapp_parts,
            'email' => $b->email,
            'latitude' => $b->latitude,
            'longitude' => $b->longitude,
            'working_hours' => $b->working_hours,
        ])->values()]);
    }

    public function brands(): JsonResponse
    {
        $brands = Brand::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->with('logo')->get();

        return response()->json(['data' => $brands->map(fn (Brand $b) => [
            'name' => $b->name,
            'slug' => $b->slug,
            'logo_url' => $b->logo?->url(),
            'is_represented' => $b->is_represented,
        ])->values()]);
    }
}
