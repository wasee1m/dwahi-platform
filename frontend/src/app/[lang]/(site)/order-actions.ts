"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser, type ApiErrorBody } from "@/lib/auth/client";
import { accessToken } from "@/lib/auth/session";

// السلة وإتمام الطلب والطلبات والإرجاع (C-10، C-11، C-13، C-14، FR-ORD-01..05)
export type OrderState = { ok?: boolean; error?: ApiErrorBody; fieldErrors?: Record<string, string[]>; message?: string };

const toLocale = (l: string): Locale => (hasLocale(l) ? l : "ar");
const lang = (form: FormData) => toLocale(String(form.get("lang")));
const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();
const fail = (error: ApiErrorBody): OrderState => ({ error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined });
const uuid = /^[0-9a-f-]{36}$/i;

/** "أضف للسلة" من صفحة القطعة. الزائر يدخل أولاً ثم يعود للقطعة نفسها. */
export async function addToCart(_prev: OrderState, form: FormData): Promise<OrderState> {
  const l = lang(form);
  const part = str(form, "part");
  const back = str(form, "back");
  if (!uuid.test(part)) return {};
  if (!(await accessToken("customer"))) redirect(`/${l}/login?next=${encodeURIComponent(back.startsWith(`/${l}/`) ? back : `/${l}/cart`)}`);
  const r = await authed("customer", l, `/me/cart/items/${part}`, { method: "PUT", body: { quantity: str(form, "quantity") || "1" } });
  if (!r.ok) {
    if (r.status === 401) redirect(`/${l}/login?next=${encodeURIComponent(back)}`);
    return fail(r.error);
  }
  revalidatePath(`/${l}/cart`);
  return { ok: true };
}

/** تعديل الكمية في السلة، و0 يحذف البند */
export async function setCartQuantity(_prev: OrderState, form: FormData): Promise<OrderState> {
  const l = lang(form);
  const part = str(form, "part");
  if (!uuid.test(part)) return {};
  const quantity = str(form, "quantity") || "0";
  const r = Number(quantity) > 0
    ? await authed("customer", l, `/me/cart/items/${part}`, { method: "PUT", body: { quantity } })
    : await authed("customer", l, `/me/cart/items/${part}`, { method: "DELETE" });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/cart`);
  revalidatePath(`/${l}/checkout`);
  return { ok: true };
}

/** العميل رأى الأسعار الجديدة ووافق عليها (US-14) */
export async function acknowledgeCart(_prev: OrderState, form: FormData): Promise<OrderState> {
  const l = lang(form);
  const r = await authed("customer", l, "/me/cart/acknowledge", { method: "POST" });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/cart`);
  return { ok: true };
}

/** 3.0: تأكيد الطلب بحساب المحدد "شخصي / الشركة" */
export async function placeOrder(_prev: OrderState, form: FormData): Promise<OrderState> {
  const l = lang(form);
  const account = await selectedAccount(await currentUser("customer", l));
  const fulfillment = str(form, "fulfillment");
  const r = await authed<{ id: string }>("customer", l, "/me/orders", {
    method: "POST",
    body: {
      fulfillment, organization_id: account.organizationId,
      branch_id: fulfillment === "pickup" ? str(form, "branch_id") || null : null,
      address_id: fulfillment === "delivery" ? str(form, "address_id") || null : null,
      notes: str(form, "notes") || null, accept_returns_policy: form.get("accept_returns_policy") === "on",
    },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  revalidatePath(`/${l}/cart`);
  redirect(`/${l}/account/orders/${r.data.id}?placed=1`);
}

/** 3.6 و 3b.1: إلغاء العميل قبل التجهيز، وطلب الإرجاع */
export async function orderAction(_prev: OrderState, form: FormData): Promise<OrderState> {
  const l = lang(form);
  const id = str(form, "order");
  if (!uuid.test(id)) return {};
  const op = str(form, "op");
  let r;
  if (op === "cancel") {
    r = await authed("customer", l, `/me/orders/${id}/cancel`, { method: "POST", body: { reason: str(form, "reason") || null } });
  } else if (op === "return") {
    const items = form.getAll("item").map(String).filter((x) => uuid.test(x))
      .map((item) => ({ order_item_id: item, quantity: str(form, `qty_${item}`) || "1" }));
    const attachment_ids = form.getAll("attachment_id").map(String).filter((x) => uuid.test(x));
    r = await authed("customer", l, `/me/orders/${id}/returns`, { method: "POST", body: { items, reason: str(form, "reason"), attachment_ids } });
  } else {
    return {};
  }
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true, message: op };
}
