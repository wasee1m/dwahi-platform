"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { apiUpload, authed } from "@/lib/auth/client";
import { accessToken } from "@/lib/auth/session";
import type { AdminState } from "../actions";
import { bool, fail, id, lang, lines, num, opt, str } from "./form-data";
import { listSep } from "@/lib/format";

// A-02 المعدات، A-03 الماركات والفئات والموديلات، A-04 القطع والمخزون (FR-CAT-06، FR-PRT-04، US-36، US-37).
// الـ API هو الحَكَم في الصلاحيات والقواعد (BR-03، BR-19)، والواجهة تعرض رسائله كما هي.
// الموقع العام يخزّن الكتالوج مؤقتاً (lib/api.ts)، فكل حفظ يُبطل وسمه ليظهر التغيير فوراً.

function changed(l: Locale, area: "equipment" | "catalog-setup" | "parts") {
  revalidatePath(`/${l}/admin/${area}`);
  if (area === "parts") updateTag("parts");
  else {
    updateTag("equipment");
    if (area === "catalog-setup") updateTag("brands");
  }
}


// ---------- A-02 المعدات المعروضة ----------
export type Media = { id: string; url: string };

export async function saveEquipment(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const existing = str(form, "id");
  const body = {
    listing_kind: str(form, "listing_kind"),
    model_id: opt(form, "model_id"),
    component_type: opt(form, "component_type"),
    component_model: opt(form, "component_model"),
    source_machine: opt(form, "source_machine"),
    missing_parts: opt(form, "missing_parts"),
    condition: str(form, "condition"),
    warranty_months: num(form, "warranty_months"),
    year: num(form, "year"),
    hour_meter: num(form, "hour_meter"),
    serial_number: opt(form, "serial_number"),
    title_ar: str(form, "title_ar"),
    title_en: str(form, "title_en"),
    description_ar: opt(form, "description_ar"),
    description_en: opt(form, "description_en"),
    price_visibility: str(form, "price_visibility"),
    price: opt(form, "price"),
    branch_code: str(form, "branch_code"),
    is_featured: bool(form, "is_featured"),
  };
  const r = await authed<{ id: string }>("admin", l, existing ? `/admin/equipment/${id(form)}` : "/admin/equipment", {
    method: existing ? "PUT" : "POST", body,
  });
  if (!r.ok) return fail(r.error);
  changed(l, "equipment");
  if (!existing) redirect(`/${l}/admin/equipment/${r.data.id}?created=1`);
  return { ok: true };
}

/** صورة واحدة في كل استدعاء، كي لا يتجاوز الطلب حد Server Actions */
export async function uploadPhoto(form: FormData): Promise<{ ok: true; media: Media } | { ok: false; message: string }> {
  const l = lang(form);
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "" };
  const body = new FormData();
  body.set("file", file);
  body.set("visibility", "public");
  const r = await apiUpload<{ id: string; url: string }>("/admin/uploads", body, { token: await accessToken("admin"), locale: l });
  if (!r.ok) {
    const details = r.error.details as Record<string, string[]> | undefined;
    return { ok: false, message: details?.file?.[0] ?? r.error.message };
  }
  return { ok: true, media: { id: r.data.id, url: r.data.url } };
}

export async function saveMedia(locale: string, listingId: string, ids: string[]): Promise<AdminState> {
  const l: Locale = hasLocale(locale) ? locale : "ar";
  const r = await authed("admin", l, `/admin/equipment/${encodeURIComponent(listingId)}/media`, { method: "PUT", body: { attachment_ids: ids } });
  if (!r.ok) return fail(r.error);
  changed(l, "equipment");
  return { ok: true };
}

export async function changeEquipmentStatus(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, `/admin/equipment/${id(form)}/status`, {
    method: "POST", body: { to: str(form, "to"), reason: opt(form, "reason") },
  });
  if (!r.ok) return fail(r.error);
  changed(l, "equipment");
  return { ok: true };
}

// ---------- A-03 الماركات والفئات والموديلات ----------
async function saveSetup(form: FormData, path: string, body: Record<string, unknown>): Promise<AdminState> {
  const l = lang(form);
  const existing = str(form, "id");
  const r = await authed("admin", l, existing ? `${path}/${id(form)}` : path, { method: existing ? "PUT" : "POST", body });
  if (!r.ok) return fail(r.error);
  changed(l, "catalog-setup");
  return { ok: true };
}

export async function saveBrand(_prev: AdminState, form: FormData): Promise<AdminState> {
  return saveSetup(form, "/admin/brands", {
    name: str(form, "name"), slug: opt(form, "slug"), is_represented: bool(form, "is_represented"),
    is_active: bool(form, "is_active"), sort_order: num(form, "sort_order") ?? 0,
  });
}

export async function saveCategory(_prev: AdminState, form: FormData): Promise<AdminState> {
  return saveSetup(form, "/admin/categories", {
    name_ar: str(form, "name_ar"), name_en: str(form, "name_en"), slug: opt(form, "slug"),
    parent_id: opt(form, "parent_id"), sort_order: num(form, "sort_order") ?? 0,
  });
}

export async function saveModel(_prev: AdminState, form: FormData): Promise<AdminState> {
  const specs = lines(str(form, "specs")).map(([label_ar = "", label_en = "", value = "", unit = ""]) => ({ label_ar, label_en, value, unit: unit || null }));
  return saveSetup(form, "/admin/models", {
    brand_id: str(form, "brand_id"), category_id: str(form, "category_id"), model_name: str(form, "model_name"),
    operating_weight_kg: num(form, "operating_weight_kg"), engine_power_kw: opt(form, "engine_power_kw"),
    bucket_capacity_m3: opt(form, "bucket_capacity_m3"), lifting_capacity_kg: num(form, "lifting_capacity_kg"), specs,
  });
}

// ---------- A-04 القطع والمخزون ----------
export async function savePart(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const existing = str(form, "id");
  const body = {
    brand_id: str(form, "brand_id"), part_number: str(form, "part_number"),
    name_ar: str(form, "name_ar"), name_en: str(form, "name_en"),
    description_ar: opt(form, "description_ar"), description_en: opt(form, "description_en"),
    category: opt(form, "category"), unit: str(form, "unit"), price: str(form, "price"), vat_rate: str(form, "vat_rate"),
    is_returnable: bool(form, "is_returnable"), warranty_months: num(form, "warranty_months"), weight_kg: opt(form, "weight_kg"),
    is_active: bool(form, "is_active"),
    alternatives: lines(str(form, "alternatives")).map(([number = "", brand = ""]) => ({ number, brand: brand || null })),
    compatible_model_ids: form.getAll("compatible_model_ids").map(String),
  };
  const r = await authed<{ id: string }>("admin", l, existing ? `/admin/parts/${id(form)}` : "/admin/parts", { method: existing ? "PUT" : "POST", body });
  if (!r.ok) return fail(r.error);
  changed(l, "parts");
  if (!existing) redirect(`/${l}/admin/parts/${r.data.id}?created=1`);
  return { ok: true };
}

export async function adjustStock(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, "/admin/stock/adjustments", {
    method: "POST", body: { part_id: str(form, "id"), location_id: str(form, "location_id"), qty_delta: str(form, "qty_delta"), reason: str(form, "reason") },
  });
  if (!r.ok) return fail(r.error);
  changed(l, "parts");
  return { ok: true };
}

const normalize = (n: string) => n.replace(/[^A-Za-z0-9]/g, "").toUpperCase();

/** "رقم | كمية" سطراً سطراً. الرقم يُطابق بعد التطبيع (FR-PRT-04)، والرقم المشترك بين ماركتين يُكتب "الماركة | الرقم | الكمية". */
export async function receiveStock(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const missing: string[] = [];
  const resolved: { part_id: string; qty: string }[] = [];

  for (const cells of lines(str(form, "lines"))) {
    const [brand, number, qty] = cells.length >= 3 ? cells : [null, cells[0], cells[1]];
    const res = await authed<{ id: string; part_number: string; brand: string }[]>("admin", l, `/admin/parts?q=${encodeURIComponent(number ?? "")}`);
    const hits = (res.ok ? res.data : []).filter((p) => normalize(p.part_number) === normalize(number ?? "") && (!brand || p.brand.toLowerCase() === brand.toLowerCase()));
    if (hits.length !== 1) missing.push(cells.join(" | "));
    else resolved.push({ part_id: hits[0].id, qty: qty ?? "" });
  }
  if (missing.length) return { error: { code: "PART_NOT_FOUND", message: missing.join(listSep(l)) } };
  if (!resolved.length) return {};

  const r = await authed("admin", l, "/admin/stock/receipts", {
    method: "POST", body: { location_id: str(form, "location_id"), note: opt(form, "note"), lines: resolved },
  });
  if (!r.ok) return fail(r.error);
  changed(l, "parts");
  return { ok: true };
}

export type ImportRow = { line: number; status: "new" | "update" | "error"; errors: string[]; values: Record<string, unknown> };
export type ImportState = AdminState & {
  preview?: { token: string | null; summary: { new: number; updated: number; errors: number; total: number }; rows: ImportRow[] };
  applied?: { created: number; updated: number };
};

export async function importStep(prev: ImportState, form: FormData): Promise<ImportState> {
  const l = lang(form);
  if (str(form, "step") === "commit") {
    const r = await authed<{ created: number; updated: number }>("admin", l, "/admin/parts/import/commit", { method: "POST", body: { token: str(form, "token") } });
    if (!r.ok) return { ...prev, error: r.error };
    changed(l, "parts");
    return { applied: r.data };
  }
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return {};
  const body = new FormData();
  body.set("file", file);
  const r = await apiUpload<NonNullable<ImportState["preview"]>>("/admin/parts/import/preview", body, { token: await accessToken("admin"), locale: l });
  if (!r.ok) return fail(r.error);
  return { preview: r.data };
}
