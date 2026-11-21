"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { authed } from "@/lib/auth/client";
import type { AdminState } from "../actions";
import { bool, fail, id, lang, muscat, num, opt, str } from "./form-data";

// A-14 المحتوى (FR-CMS-01، FR-CMS-03، FR-CMS-04، US-42) و A-15 الاستفسارات (FR-CMS-05، US-43).
// الموقع يخزّن المحتوى مؤقتاً بالوسوم (lib/api.ts)، فكل حفظ يُبطل وسمه.

async function save(form: FormData, path: string, body: Record<string, unknown>, tag: string) {
  const l = lang(form);
  const existing = str(form, "id");
  const r = await authed<{ id: string; slug?: string }>("admin", l, existing ? `${path}/${id(form)}` : path, { method: existing ? "PUT" : "POST", body });
  if (r.ok) {
    updateTag(tag);
    revalidatePath(`/${l}/admin/content`);
  }
  return { r, l, existing };
}

export async function savePage(_prev: AdminState, form: FormData): Promise<AdminState> {
  const { r, l, existing } = await save(form, "/admin/pages", {
    ...(str(form, "id") ? {} : { slug: str(form, "slug") }),
    title_ar: str(form, "title_ar"), title_en: str(form, "title_en"),
    body_ar: str(form, "body_ar"), body_en: str(form, "body_en"),
    seo_title_ar: opt(form, "seo_title_ar"), seo_title_en: opt(form, "seo_title_en"),
    seo_description_ar: opt(form, "seo_description_ar"), seo_description_en: opt(form, "seo_description_en"),
    status: str(form, "status"),
  }, "pages");
  if (!r.ok) return fail(r.error);
  if (!existing) redirect(`/${l}/admin/content/pages/${r.data.id}?created=1`);
  return { ok: true };
}

export async function saveFaq(_prev: AdminState, form: FormData): Promise<AdminState> {
  const { r } = await save(form, "/admin/faqs", {
    section: str(form, "section"), question_ar: str(form, "question_ar"), question_en: str(form, "question_en"),
    answer_ar: str(form, "answer_ar"), answer_en: str(form, "answer_en"), is_active: bool(form, "is_active"),
  }, "faqs");
  return r.ok ? { ok: true } : fail(r.error);
}

export async function orderFaqs(locale: string, ids: string[]): Promise<AdminState> {
  const f = new FormData();
  f.set("lang", locale);
  const l = lang(f);
  const r = await authed("admin", l, "/admin/faqs/order", { method: "PUT", body: { ids } });
  if (!r.ok) return fail(r.error);
  updateTag("faqs");
  revalidatePath(`/${l}/admin/content`);
  return { ok: true };
}

export async function saveBanner(_prev: AdminState, form: FormData): Promise<AdminState> {
  const { r } = await save(form, "/admin/banners", {
    placement: str(form, "placement"), image_ar_id: str(form, "image_ar_id"), image_en_id: str(form, "image_en_id"),
    link: opt(form, "link"), starts_at: muscat(str(form, "starts_at")), ends_at: muscat(str(form, "ends_at")),
    sort_order: num(form, "sort_order") ?? 0, is_active: bool(form, "is_active"),
  }, "banners");
  return r.ok ? { ok: true } : fail(r.error);
}

// ---------- A-15 الاستفسارات ----------
/**
 * "تحويل لطلب عرض سعر" (US-43): العميل برقم الاستفسار (يُنشأ حسابه إن لم يوجد)، وطلب بمصدر الهاتف،
 * والاستفسار يصبح "فرصة مؤكدة" بملاحظة تحمل رقم الطلب. استفسارات الصيانة تتحول مع وحدة الصيانة (S7).
 */
export async function convertLead(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const customer = await authed<{ id: string }>("admin", l, "/admin/customers", { method: "POST", body: { phone: str(form, "phone"), full_name: str(form, "name") } });
  if (!customer.ok) return fail(customer.error);
  const message = str(form, "message");
  const rfq = await authed<{ id: string; number: string }>("admin", l, "/admin/rfqs", {
    method: "POST",
    body: { customer_id: customer.data.id, source: "phone", type: str(form, "topic") === "equipment" ? "equipment" : "parts", notes: message, items: [{ free_text: message.slice(0, 2000), quantity: 1 }] },
  });
  if (!rfq.ok) return fail(rfq.error);
  await authed("admin", l, `/admin/leads/${id(form)}`, { method: "PATCH", body: { status: "qualified", note: `→ ${rfq.data.number}` } });
  redirect(`/${l}/admin/rfqs/${rfq.data.id}`);
}

export async function updateLead(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, `/admin/leads/${id(form)}`, {
    method: "PATCH", body: { status: str(form, "status"), assigned_to: opt(form, "assigned_to"), note: opt(form, "note") },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/leads`);
  return { ok: true };
}
