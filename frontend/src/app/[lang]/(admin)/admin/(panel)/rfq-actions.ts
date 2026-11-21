"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { apiUpload, authed } from "@/lib/auth/client";
import { accessToken } from "@/lib/auth/session";
import type { AdminState } from "../actions";
import { fail, id, lang, lines, str } from "./form-data";

// A-05 طلبات العروض و A-06 محرر العرض (FR-RFQ-01، FR-RFQ-02، FR-RFQ-05، US-32). الـ API هو الحَكَم في الانتقالات (SM-1، SM-2).
const toLocale = (l: string): Locale => (hasLocale(l) ? l : "ar");

export async function rfqAction(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const rfq = id(form);
  const op = str(form, "op");
  const calls: Record<string, [string, Record<string, unknown>]> = {
    assign: [`/admin/rfqs/${rfq}/assign`, { user_id: str(form, "user_id") || null }],
    lose: [`/admin/rfqs/${rfq}/lose`, { reason: str(form, "reason") }],
    reopen: [`/admin/rfqs/${rfq}/reopen`, { reason: str(form, "reason") }],
  };
  if (op === "draft") {
    const r = await authed<{ id: string }>("admin", l, `/admin/rfqs/${rfq}/quotations`, { method: "POST" });
    if (!r.ok) return fail(r.error);
    redirect(`/${l}/admin/quotations/${r.data.id}`);
  }
  if (!calls[op]) return {};
  const r = await authed("admin", l, calls[op][0], { method: "POST", body: calls[op][1] });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/rfqs`);
  return { ok: true };
}

export type Line = {
  item_type: string; listing_id?: string | null; part_id?: string | null; description_ar: string; description_en: string;
  quantity: string; unit: string; unit_price: string; discount_amount: string; vat_rate: string; warranty_months?: number | null;
};
export type QuoteTerms = { valid_until: string | null; estimated_duration: string | null; delivery_terms: string | null; warranty_terms: string | null; payment_terms: string | null };

export async function saveQuote(locale: string, quotationId: string, items: Line[], terms: QuoteTerms): Promise<AdminState & { totals?: Record<string, { amount: string }>; items?: (Line & { line_vat: string; line_total: string })[] }> {
  const l = toLocale(locale);
  const r = await authed<{ items: (Line & { line_vat: string; line_total: string })[]; subtotal: { amount: string }; discount_total: { amount: string }; vat_total: { amount: string }; total: { amount: string } }>(
    "admin", l, `/admin/quotations/${encodeURIComponent(quotationId)}`, { method: "PUT", body: { ...terms, items } });
  if (!r.ok) return fail(r.error);
  return { ok: true, items: r.data.items, totals: { subtotal: r.data.subtotal, discount_total: r.data.discount_total, vat_total: r.data.vat_total, total: r.data.total } };
}

export async function sendQuote(locale: string, quotationId: string, rfqId: string): Promise<AdminState> {
  const l = toLocale(locale);
  const r = await authed("admin", l, `/admin/quotations/${encodeURIComponent(quotationId)}/send`, { method: "POST" });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/rfqs`);
  redirect(`/${l}/admin/rfqs/${rfqId}?sent=1`);
}

export async function discardQuote(locale: string, quotationId: string, rfqId: string): Promise<AdminState> {
  const l = toLocale(locale);
  const r = await authed("admin", l, `/admin/quotations/${encodeURIComponent(quotationId)}`, { method: "DELETE" });
  if (!r.ok) return fail(r.error);
  redirect(`/${l}/admin/rfqs/${rfqId}`);
}

export type CatalogHit = Line & { label: string };

export async function searchCatalog(locale: string, q: string): Promise<CatalogHit[]> {
  if (q.trim().length < 2) return [];
  const r = await authed<CatalogHit[]>("admin", toLocale(locale), `/admin/quote-catalog?q=${encodeURIComponent(q)}`);
  return r.ok ? r.data : [];
}

// ---------- طلب نيابة عن عميل (A-05) ----------
export type Customer = { id: string; full_name: string; phone: string; needs_onboarding: boolean };

export async function findCustomer(locale: string, phone: string): Promise<{ customer: Customer | null; phone: string | null }> {
  const r = await authed<Customer | null>("admin", toLocale(locale), `/admin/customers/lookup?phone=${encodeURIComponent(phone)}`);
  return r.ok ? { customer: r.data, phone: (r.meta.phone as string | null) ?? null } : { customer: null, phone: null };
}

export async function createCustomer(locale: string, phone: string, fullName: string): Promise<{ ok: true; customer: Customer } | { ok: false; message: string }> {
  const r = await authed<Customer>("admin", toLocale(locale), "/admin/customers", { method: "POST", body: { phone, full_name: fullName } });
  return r.ok ? { ok: true, customer: r.data } : { ok: false, message: Object.values((r.error.details as Record<string, string[]>) ?? {}).flat()[0] ?? r.error.message };
}

export async function createOnBehalf(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const items = lines(str(form, "items")).map(([text = "", qty = "1"]) => ({ free_text: text, quantity: qty || "1" }));
  const r = await authed<{ id: string }>("admin", l, "/admin/rfqs", {
    method: "POST",
    body: { customer_id: str(form, "customer_id"), source: str(form, "source"), type: str(form, "type"), notes: str(form, "notes") || null, items },
  });
  if (!r.ok) return fail(r.error);
  redirect(`/${l}/admin/rfqs/${r.data.id}`);
}

// ---------- إشعارات الموظف ----------
export async function openStaffNotification(form: FormData) {
  const l = lang(form);
  await authed("admin", l, `/admin/notifications/${id(form)}/read`, { method: "POST" });
  const link = str(form, "link");
  revalidatePath(`/${l}/admin`, "layout");
  redirect(/^\/admin(\/|$|#)/.test(link) ? `/${l}${link}` : `/${l}/admin/notifications`);
}

export async function readAllStaffNotifications(form: FormData) {
  const l = lang(form);
  await authed("admin", l, "/admin/notifications/read-all", { method: "POST" });
  revalidatePath(`/${l}/admin`, "layout");
}

// ---------- المحادثة ----------
export type ThreadMessage = {
  id: string; body: string | null; mine: boolean; sender: { name: string | null; is_staff: boolean };
  attachments: { id: string; name: string | null; mime_type: string; url: string | null }[]; at: string;
};

export async function staffMessage(locale: string, rfqId: string, msgId: string, body: string, attachmentIds: string[]): Promise<{ ok: true; thread: ThreadMessage[] } | { ok: false; message: string }> {
  const r = await authed<ThreadMessage[]>("admin", toLocale(locale), `/admin/rfqs/${encodeURIComponent(rfqId)}/messages`, {
    method: "POST", body: { id: msgId, body: body || null, attachment_ids: attachmentIds },
  });
  return r.ok ? { ok: true, thread: r.data } : { ok: false, message: r.error.message };
}

export async function staffUpload(form: FormData): Promise<{ ok: true; id: string; name: string } | { ok: false; message: string }> {
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "" };
  const body = new FormData();
  body.set("file", file);
  const r = await apiUpload<{ id: string; name: string }>("/admin/staff-uploads", body, { token: await accessToken("admin"), locale: lang(form) });
  return r.ok ? { ok: true, id: r.data.id, name: r.data.name } : { ok: false, message: r.error.message };
}
