"use server";

import { revalidatePath } from "next/cache";
import { authed } from "@/lib/auth/client";
import type { AdminState } from "../actions";
import { fail, lang, muscat, opt, str } from "./form-data";

// A-07 طلبات القطع والإرجاع، و A-13 المالية. الـ API هو الحَكَم في الانتقالات (SM-3a، SM-3b، SM-6).
const uuid = /^[0-9a-f-]{36}$/i;

/** انتقالات التجهيز والإلغاء وتسجيل الاتصال (3.5 إلى 3.10، BR-18) */
export async function orderStep(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const order = str(form, "id");
  const op = str(form, "op");
  if (!uuid.test(order) || !["process", "ready", "dispatch", "delivery-failed", "cancel", "phone"].includes(op)) return {};
  const body: Record<string, unknown> = { reason: opt(form, "reason") };
  if (op === "phone") body.confirmed = str(form, "confirmed") === "1";
  const r = await authed("admin", l, `/admin/orders/${order}/${op}`, { method: "POST", body });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/orders`, "layout");
  return { ok: true, message: op };
}

/** 3.11 و 3.12: التسليم برمز الاستلام أو إثبات التوصيل، والدفعة في نفس الخطوة */
export async function deliverOrder(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const order = str(form, "id");
  if (!uuid.test(order)) return {};
  const amount = str(form, "amount");
  const r = await authed("admin", l, `/admin/orders/${order}/deliver`, {
    method: "POST",
    body: {
      code: opt(form, "code"),
      proof_id: opt(form, "proof_id"),
      payment: amount ? { method: str(form, "method"), amount, reference_text: opt(form, "reference_text"), proof_id: opt(form, "payment_proof_id") } : null,
    },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/orders`, "layout");
  return { ok: true, message: "delivered" };
}

/** 3.14 يدوياً: طلب القطع من عرض قطع مقبول */
export async function orderFromQuote(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const rfq = str(form, "id");
  if (!uuid.test(rfq)) return {};
  const r = await authed("admin", l, `/admin/rfqs/${rfq}/sales-order`, { method: "POST", body: {} });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/rfqs/${rfq}`);
  return { ok: true };
}

/** SM-3b: قرار الإرجاع واستلامه، والاسترداد للمحاسب */
export async function returnStep(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const rr = str(form, "id");
  const op = str(form, "op");
  if (!uuid.test(rr) || !["approve", "reject", "receive", "refund"].includes(op)) return {};
  const restock = Object.fromEntries(form.getAll("item").map(String).filter((x) => uuid.test(x)).map((x) => [x, form.get(`restock_${x}`) === "on"]));
  const r = await authed("admin", l, `/admin/returns/${rr}/${op}`, {
    method: "POST",
    body: { note: opt(form, "note"), restock, method: opt(form, "method"), reference_text: opt(form, "reference_text") },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/orders`, "layout");
  revalidatePath(`/${l}/admin/finance`, "layout");
  return { ok: true, message: op };
}

/** A-13: إصدار الفاتورة وإلغاؤها وتسجيل دفعة واسترداد دفعة */
export async function invoiceStep(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const invoice = str(form, "id");
  const op = str(form, "op");
  if (!uuid.test(invoice)) return {};
  const calls: Record<string, [string, Record<string, unknown>]> = {
    issue: [`/admin/invoices/${invoice}/issue`, { due_date: opt(form, "due_date") }],
    void: [`/admin/invoices/${invoice}/void`, { reason: str(form, "reason") }],
    payment: [`/admin/invoices/${invoice}/payments`, {
      method: str(form, "method"), amount: str(form, "amount"), reference_text: opt(form, "reference_text"), proof_id: opt(form, "proof_id"),
      received_at: muscat(str(form, "received_at")),
    }],
    refund: [`/admin/payments/${encodeURIComponent(str(form, "payment"))}/refunds`, {
      amount: str(form, "amount"), method: str(form, "method"), reason: str(form, "reason"), reference_text: opt(form, "reference_text"),
    }],
  };
  if (!calls[op]) return {};
  const r = await authed("admin", l, calls[op][0], { method: "POST", body: calls[op][1] });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/finance`, "layout");
  return { ok: true, message: op };
}
