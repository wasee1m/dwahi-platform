"use server";

import { revalidatePath } from "next/cache";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { ACCOUNT_COOKIE } from "@/lib/auth/account";
import { authed, type ApiErrorBody } from "@/lib/auth/client";
import { clearSession } from "@/lib/auth/session";
import type { ActionState } from "./actions";

// إجراءات S5: الشركة (C-03)، والعناوين (C-04)، والأسطول (C-05، C-06)، والإعدادات والخصوصية (C-20).
// الصلاحيات وقواعد الرؤية في الـ API، وهنا نقل البيانات فقط.
export type PortalState = ActionState & { previous?: number };

function lang(form: FormData): Locale {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
}
const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();
const opt = (form: FormData, k: string) => str(form, k) || null;
const num = (form: FormData, k: string) => (str(form, k) === "" ? null : Number(str(form, k)));
const seg = (form: FormData, k: string) => encodeURIComponent(str(form, k));

function fail(error: ApiErrorBody): PortalState {
  return { error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined };
}

async function remember(account: string) {
  (await cookies()).set(ACCOUNT_COOKIE, account, {
    httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/", maxAge: 365 * 86_400,
  });
}

async function send(form: FormData, path: string, method: string, body?: unknown): Promise<PortalState> {
  const l = lang(form);
  const r = await authed("customer", l, path, { method, body });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true };
}

export async function chooseAccount(form: FormData) {
  await remember(str(form, "account"));
  revalidatePath(`/${lang(form)}/account`, "layout");
}

// ---------- C-03 الشركة والأعضاء ----------

export async function createCompany(_prev: PortalState, form: FormData): Promise<PortalState> {
  const l = lang(form);
  const r = await authed<{ id: string }>("customer", l, "/me/organizations", {
    method: "POST",
    body: { name: str(form, "name"), governorate: str(form, "governorate"), cr_number: opt(form, "cr_number"), vat_number: opt(form, "vat_number") },
  });
  if (!r.ok) return fail(r.error);
  await remember(r.data.id);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true };
}

export async function updateCompany(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/organizations/${seg(form, "company")}`, "PATCH", {
    name: str(form, "name"), governorate: str(form, "governorate"), cr_number: opt(form, "cr_number"),
    vat_number: opt(form, "vat_number"), preapproval_limit: str(form, "preapproval_limit") || "0",
  });
}

export async function inviteMember(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/organizations/${seg(form, "company")}/invitations`, "POST", {
    phone: str(form, "phone"), role: str(form, "role"), can_approve_quotes: form.get("can_approve_quotes") === "on",
  });
}

export async function revokeInvitation(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/organizations/${seg(form, "company")}/invitations/${seg(form, "invitation")}`, "DELETE");
}

export async function updateMember(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/organizations/${seg(form, "company")}/members/${seg(form, "member")}`, "PATCH", {
    role: str(form, "role"), can_approve_quotes: form.get("can_approve_quotes") === "on",
  });
}

export async function removeMember(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/organizations/${seg(form, "company")}/members/${seg(form, "member")}`, "DELETE");
}

export async function answerInvitation(_prev: PortalState, form: FormData): Promise<PortalState> {
  const l = lang(form);
  const accept = str(form, "answer") === "accept";
  const r = await authed<{ id: string } | undefined>("customer", l, `/me/invitations/${seg(form, "invitation")}/${accept ? "accept" : "decline"}`, { method: "POST" });
  if (!r.ok) return fail(r.error);
  if (accept && r.data) await remember(r.data.id);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true };
}

// ---------- C-04 العناوين ----------

export async function saveAddress(_prev: PortalState, form: FormData): Promise<PortalState> {
  const id = str(form, "id");
  const body = {
    label: str(form, "label"), kind: str(form, "kind"), governorate: str(form, "governorate"), wilayat: str(form, "wilayat"),
    description: opt(form, "description"), latitude: num(form, "latitude"), longitude: num(form, "longitude"),
    contact_name: opt(form, "contact_name"), contact_phone: opt(form, "contact_phone"),
  };
  return id
    ? send(form, `/me/addresses/${encodeURIComponent(id)}`, "PATCH", body)
    : send(form, "/me/addresses", "POST", { ...body, organization_id: opt(form, "organization_id") });
}

export async function archiveAddress(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, `/me/addresses/${seg(form, "id")}`, "DELETE");
}

// ---------- C-05، C-06 الأسطول ----------

export async function saveEquipment(_prev: PortalState, form: FormData): Promise<PortalState> {
  const l = lang(form);
  const id = str(form, "id");
  const modelId = opt(form, "model_id");
  const body = {
    model_id: modelId, brand_text: modelId ? null : opt(form, "brand_text"), model_text: modelId ? null : opt(form, "model_text"),
    serial_number: opt(form, "serial_number"), year: num(form, "year"), nickname: opt(form, "nickname"),
    site_address_id: opt(form, "site_address_id"),
    ...(str(form, "nameplate_photo_id") ? { nameplate_photo_id: str(form, "nameplate_photo_id") } : {}),
  };
  if (id) return send(form, `/me/fleet/${encodeURIComponent(id)}`, "PATCH", body);

  const r = await authed<{ id: string }>("customer", l, "/me/fleet", {
    method: "POST", body: { ...body, organization_id: opt(form, "organization_id"), hour_meter: num(form, "hour_meter") },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account/fleet`);
  redirect(`/${l}/account/fleet/${r.data.id}`);
}

export async function archiveEquipment(_prev: PortalState, form: FormData): Promise<PortalState> {
  const r = await send(form, `/me/fleet/${seg(form, "id")}`, "DELETE");
  if (!r.ok) return r;
  redirect(`/${lang(form)}/account/fleet`);
}

/** القراءة الأقل: الـ API يحذر أولاً (409)، ثم يقبل بتأكيد وملاحظة (US-10) */
export async function addReading(prev: PortalState, form: FormData): Promise<PortalState> {
  const confirm = str(form, "confirm_lower") === "1";
  const r = await send(form, `/me/fleet/${seg(form, "id")}/hour-readings`, "POST", {
    reading: num(form, "reading"), confirm_lower: confirm, note: opt(form, "note"), photo_id: opt(form, "photo_id"),
  });
  if (r.error?.code === "HOUR_METER_LOWER") return { step: "confirm", previous: Number(r.error.details?.previous) };
  return r.error && confirm ? { ...r, step: "confirm", previous: prev.previous } : r;
}

// ---------- C-20 الإعدادات والخصوصية ----------

export async function savePreferences(_prev: PortalState, form: FormData): Promise<PortalState> {
  // المفاتيح القابلة للتغيير فقط، وغير المؤشَّر منها = معطّل
  const preferences = form.getAll("editable").map(String).map((key) => {
    const [category, channel] = key.split(":");
    return { category, channel, enabled: form.get(key) === "on" };
  });
  return send(form, "/me/notification-preferences", "PUT", { preferences });
}

export async function setMarketing(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, "/me/marketing-consent", "PUT", { granted: str(form, "granted") === "1" });
}

export async function requestExport(_prev: PortalState, form: FormData): Promise<PortalState> {
  return send(form, "/me/data-export", "POST");
}

export async function deleteAccount(_prev: PortalState, form: FormData): Promise<PortalState> {
  const l = lang(form);
  if (str(form, "step") !== "confirm") {
    const r = await authed<{ debug_code: string | null }>("customer", l, "/me/deletion/request", { method: "POST" });
    return r.ok ? { step: "confirm", debugCode: r.data.debug_code ?? undefined } : fail(r.error);
  }
  const r = await authed("customer", l, "/me/deletion", { method: "POST", body: { code: str(form, "code").replace(/\D/g, "") } });
  if (!r.ok) return { ...fail(r.error), step: "confirm" };
  await clearSession("customer");
  (await cookies()).delete(ACCOUNT_COOKIE);
  redirect(`/${l}/login/deleted`);
}

// ---------- توثيق البريد (C-02) ----------

export async function verifyEmail(_prev: PortalState, form: FormData): Promise<PortalState> {
  const l = lang(form);
  if (str(form, "step") !== "confirm") {
    const r = await authed<{ debug_code: string | null }>("customer", l, "/me/email/request", { method: "POST" });
    return r.ok ? { step: "confirm", debugCode: r.data.debug_code ?? undefined } : fail(r.error);
  }
  const r = await send(form, "/me/email/verify", "POST", { code: str(form, "code").replace(/\D/g, "") });
  return r.ok ? r : { ...r, step: "confirm" };
}
