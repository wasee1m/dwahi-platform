"use server";

import { revalidatePath, updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { searchParts } from "@/lib/api";
import { apiUpload, authed, type ApiErrorBody } from "@/lib/auth/client";
import { accessToken } from "@/lib/auth/session";

// طلبات العروض والرد عليها والمحادثة والإشعارات (C-07، C-08، C-09، C-19، FR-RFQ-01، FR-RFQ-03، FR-MSG-01)
export type QuoteState = { ok?: boolean; error?: ApiErrorBody; fieldErrors?: Record<string, string[]>; message?: string };

const toLocale = (l: string): Locale => (hasLocale(l) ? l : "ar");
const lang = (form: FormData) => toLocale(String(form.get("lang")));
const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();
const fail = (error: ApiErrorBody): QuoteState => ({ error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined });

export type PartHit = { id: string; part_number: string; name: string; brand: { name: string } };

export async function findParts(locale: string, q: string): Promise<PartHit[]> {
  if (q.trim().length < 2) return [];
  return (await searchParts(toLocale(locale), q)).slice(0, 8) as unknown as PartHit[];
}

/** ملف خاص واحد في كل استدعاء: صورة القطعة، أو ملف أمر الشراء، أو مرفق رسالة */
export async function uploadPrivate(form: FormData): Promise<{ ok: true; id: string; url: string | null; name: string } | { ok: false; message: string }> {
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return { ok: false, message: "" };
  const body = new FormData();
  body.set("file", file);
  const r = await apiUpload<{ id: string; url: string | null; name: string }>("/me/uploads", body, { token: await accessToken("customer"), locale: lang(form) });
  if (!r.ok) return { ok: false, message: (r.error.details as Record<string, string[]> | undefined)?.file?.[0] ?? r.error.message };
  return { ok: true, id: r.data.id, url: r.data.url, name: r.data.name };
}

export type DraftItem = { listing_id?: string; part_id?: string; free_text?: string; quantity: string; attachment_ids?: string[]; specs?: Record<string, string> };

export async function createRfq(locale: string, payload: { type: string; items: DraftItem[]; notes: string; delivery_country: string; delivery_address_text: string; attachment_ids: string[]; organization_id: string | null; delivery_address_id: string | null; customer_equipment_id: string | null }): Promise<QuoteState> {
  const l = toLocale(locale);
  const items = payload.items.map((i) => ({
    ...i,
    specs: i.specs && Object.values(i.specs).some(Boolean) ? Object.fromEntries(Object.entries(i.specs).filter(([, v]) => v !== "")) : undefined,
  }));
  const r = await authed<{ id: string }>("customer", l, "/me/rfqs", {
    method: "POST",
    body: { type: payload.type, items, notes: payload.notes || null, delivery_country: payload.delivery_country || "OM", delivery_address_text: payload.delivery_address_text || null, attachment_ids: payload.attachment_ids, organization_id: payload.organization_id, delivery_address_id: payload.delivery_address_id, customer_equipment_id: payload.customer_equipment_id },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  redirect(`/${l}/account/quotes/${r.data.id}?sent=1`);
}

/** القبول والرفض وطلب التعديل وطلب الاعتماد، والتجديد والإلغاء (SM-1، SM-2) */
export async function quoteAction(_prev: QuoteState, form: FormData): Promise<QuoteState> {
  const l = lang(form);
  const op = str(form, "op");
  const rfq = encodeURIComponent(str(form, "rfq"));
  const q = encodeURIComponent(str(form, "quotation"));
  const call: Record<string, [string, Record<string, unknown>]> = {
    accept: [`/me/quotations/${q}/accept`, { lpo_number: str(form, "lpo_number") || null, lpo_attachment_id: str(form, "lpo_attachment_id") || null }],
    reject: [`/me/quotations/${q}/reject`, { reason: str(form, "reason") || null }],
    revision: [`/me/quotations/${q}/revision`, { note: str(form, "note") }],
    approval: [`/me/quotations/${q}/request-approval`, {}],
    renew: [`/me/rfqs/${rfq}/renew`, {}],
    cancel: [`/me/rfqs/${rfq}/cancel`, { reason: str(form, "reason") || null }],
  };
  if (!call[op]) return {};
  const r = await authed("customer", l, call[op][0], { method: "POST", body: call[op][1] });
  if (!r.ok) return fail(r.error);
  if (op === "accept") updateTag("equipment"); // القبول يحجز المعدة (BR-19)، فتظهر "محجوزة" في الكتالوج فوراً
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true, message: op };
}

export type ThreadMessage = {
  id: string; body: string | null; mine: boolean; sender: { name: string | null; is_staff: boolean };
  attachments: { id: string; name: string | null; mime_type: string; url: string | null }[]; at: string;
};

export async function sendMessage(locale: string, rfqId: string, id: string, body: string, attachmentIds: string[]): Promise<{ ok: true; thread: ThreadMessage[] } | { ok: false; message: string }> {
  const r = await authed<ThreadMessage[]>("customer", toLocale(locale), `/me/rfqs/${encodeURIComponent(rfqId)}/messages`, {
    method: "POST", body: { id, body: body || null, attachment_ids: attachmentIds },
  });
  return r.ok ? { ok: true, thread: r.data } : { ok: false, message: r.error.message };
}

// ---------- الإشعارات (C-19) ----------
export async function openNotification(form: FormData) {
  const l = lang(form);
  await authed("customer", l, `/me/notifications/${encodeURIComponent(str(form, "id"))}/read`, { method: "POST" });
  const link = str(form, "link");
  revalidatePath(`/${l}/account`, "layout");
  redirect(/^\/account(\/|$|#)/.test(link) ? `/${l}${link}` : `/${l}/account/notifications`);
}

export async function readAllNotifications(form: FormData) {
  const l = lang(form);
  await authed("customer", l, "/me/notifications/read-all", { method: "POST" });
  revalidatePath(`/${l}/account`, "layout");
}
