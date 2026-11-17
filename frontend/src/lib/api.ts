import "server-only";
import { cacheLife, cacheTag } from "next/cache";
import type { Locale } from "@/i18n/config";
import type {
  Banner, Branch, Brand, ContentPage, EquipmentCard, EquipmentDetail, Facets, Faq, Page, PartCard, PartDetail,
} from "./types";

// كل القراءات العامة من الـ API تمر من هنا. المحتوى نفسه لكل الزوار، فيُخزَّن مؤقتاً دقائق
// ويُعاد التحقق منه في الخلفية (Cache Components). الكتابة (الاستفسار) لا تمر من هنا.
const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export class ApiError extends Error {
  constructor(public status: number, public traceId: string | null, message: string) {
    super(message);
  }
}

async function get<T>(path: string, locale: Locale): Promise<T | null> {
  const res = await fetch(`${API_URL}${path}`, {
    headers: { Accept: "application/json", "Accept-Language": locale },
  });
  if (res.status === 404) return null;
  if (!res.ok) throw new ApiError(res.status, res.headers.get("x-trace-id"), `GET ${path} → ${res.status}`);
  return (await res.json()) as T;
}

const qs = (params: Record<string, string | undefined>) => {
  const s = new URLSearchParams(Object.entries(params).filter(([, v]) => v) as [string, string][]).toString();
  return s ? `?${s}` : "";
};

export type EquipmentFilters = {
  kind?: string; category?: string; brand?: string; condition?: string; sort?: string; cursor?: string;
};

export async function listEquipment(locale: Locale, filters: EquipmentFilters) {
  "use cache";
  cacheLife("minutes");
  cacheTag("equipment");
  return (await get<Page<EquipmentCard>>(`/equipment${qs(filters)}`, locale)) ?? { data: [], next_cursor: null };
}

export async function featuredEquipment(locale: Locale) {
  "use cache";
  cacheLife("minutes");
  cacheTag("equipment");
  return (await get<Page<EquipmentCard>>(`/equipment?featured=1&limit=6`, locale))?.data ?? [];
}

export async function equipmentFacets(locale: Locale, filters: EquipmentFilters) {
  "use cache";
  cacheLife("minutes");
  cacheTag("equipment");
  // الترتيب والصفحة لا يغيّران العدّادات
  const rest = { kind: filters.kind, category: filters.category, brand: filters.brand, condition: filters.condition };
  return await get<Facets>(`/equipment/facets${qs(rest)}`, locale);
}

export async function getEquipment(locale: Locale, slug: string) {
  "use cache";
  cacheLife("minutes");
  cacheTag("equipment", `equipment:${slug}`);
  return (await get<{ data: EquipmentDetail }>(`/equipment/${encodeURIComponent(slug)}`, locale))?.data ?? null;
}

export async function searchParts(locale: Locale, q: string) {
  "use cache";
  cacheLife("minutes");
  cacheTag("parts");
  if (!q.trim()) return [];
  return (await get<{ data: PartCard[] }>(`/parts/search${qs({ q })}`, locale))?.data ?? [];
}

export async function getPart(locale: Locale, brand: string, number: string) {
  "use cache";
  cacheLife("minutes");
  cacheTag("parts");
  const path = `/parts/${encodeURIComponent(brand)}/${encodeURIComponent(number)}`;
  return (await get<{ data: PartDetail }>(path, locale))?.data ?? null;
}

export async function listBranches(locale: Locale) {
  "use cache";
  cacheLife("hours");
  cacheTag("branches");
  return (await get<{ data: Branch[] }>(`/public/branches`, locale))?.data ?? [];
}

export async function listBrands(locale: Locale) {
  "use cache";
  cacheLife("hours");
  cacheTag("brands");
  return (await get<{ data: Brand[] }>(`/public/brands`, locale))?.data ?? [];
}

export async function getContentPage(locale: Locale, slug: string) {
  "use cache";
  cacheLife("hours");
  cacheTag("pages", `page:${slug}`);
  return (await get<{ data: ContentPage }>(`/public/pages/${encodeURIComponent(slug)}`, locale))?.data ?? null;
}

export async function listFaqs(locale: Locale) {
  "use cache";
  cacheLife("hours");
  cacheTag("faqs");
  return (await get<{ data: Faq[] }>(`/public/faqs`, locale))?.data ?? [];
}

export async function listBanners(locale: Locale, placement: string) {
  "use cache";
  cacheLife("minutes");
  cacheTag("banners");
  return (await get<{ data: Banner[] }>(`/public/banners${qs({ placement })}`, locale))?.data ?? [];
}

// الكتابة: نموذج الاستفسار (FR-CMS-05). يُستدعى من Server Action فقط، فلا يظهر عنوان الـ API للمتصفح.
export async function postLead(locale: Locale, body: Record<string, string>) {
  const res = await fetch(`${API_URL}/public/leads`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "Accept-Language": locale },
    body: JSON.stringify(body),
    cache: "no-store",
  });
  if (res.status === 422) {
    const json = (await res.json()) as { error?: { details?: Record<string, string[]> } };
    return { ok: false as const, fieldErrors: json.error?.details ?? {} };
  }
  if (!res.ok) return { ok: false as const, fieldErrors: {} };
  return { ok: true as const };
}
