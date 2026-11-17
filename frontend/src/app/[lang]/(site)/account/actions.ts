"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { authed, type ApiErrorBody } from "@/lib/auth/client";
import { clearSession } from "@/lib/auth/session";

// إجراءات الحساب (FR-ACC-01، FR-AUTH-04، US-06، US-07، US-08). كل استدعاء باسم المستخدم عبر خادم Next.js.
export type ActionState = { ok?: boolean; error?: ApiErrorBody; fieldErrors?: Record<string, string[]>; debugCode?: string; step?: string };

function lang(form: FormData): Locale {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
}
const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();

function fail(error: ApiErrorBody): ActionState {
  return { error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined };
}

export async function completeOnboarding(_prev: ActionState, form: FormData): Promise<ActionState> {
  const l = lang(form);
  const company = str(form, "account_type") === "company";
  const r = await authed("customer", l, "/me/onboarding", {
    method: "POST",
    body: {
      full_name: str(form, "full_name"),
      account_type: company ? "company" : "individual",
      company_name: company ? str(form, "company_name") : null,
      governorate: company ? str(form, "governorate") || null : null,
      cr_number: company ? str(form, "cr_number") || null : null,
      vat_number: company ? str(form, "vat_number") || null : null,
      email: str(form, "email") || null,
      accept_terms: form.get("accept_terms") === "on",
      accept_privacy: form.get("accept_privacy") === "on",
      marketing: form.get("marketing") === "on",
    },
  });
  if (!r.ok) return fail(r.error);
  redirect(`/${l}/account`);
}

export async function updateProfile(_prev: ActionState, form: FormData): Promise<ActionState> {
  const l = lang(form);
  const r = await authed("customer", l, "/me", {
    method: "PATCH",
    body: { full_name: str(form, "full_name"), email: str(form, "email") || null, preferred_locale: str(form, "preferred_locale") },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true };
}

export async function changePhone(prev: ActionState, form: FormData): Promise<ActionState> {
  const l = lang(form);
  const phone = str(form, "phone");
  if (str(form, "step") === "verify") {
    const r = await authed("customer", l, "/me/phone/verify", { method: "POST", body: { phone, code: str(form, "code") } });
    if (!r.ok) return { ...fail(r.error), step: "verify" };
    revalidatePath(`/${l}/account`, "layout");
    return { ok: true, step: "done" };
  }
  const r = await authed<{ debug_code?: string }>("customer", l, "/me/phone/request", { method: "POST", body: { phone } });
  if (!r.ok) return fail(r.error);
  return { step: "verify", debugCode: r.data.debug_code };
}

export async function setPassword(_prev: ActionState, form: FormData): Promise<ActionState> {
  const l = lang(form);
  const r = await authed("customer", l, "/me/password", {
    method: "POST",
    body: {
      password: String(form.get("password") ?? ""),
      password_confirmation: String(form.get("password_confirmation") ?? ""),
      current_password: String(form.get("current_password") ?? "") || null,
    },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account/profile`);
  return { ok: true };
}

export async function endSession(form: FormData) {
  const l = lang(form);
  await authed("customer", l, `/me/sessions/${encodeURIComponent(str(form, "id"))}`, { method: "DELETE" });
  revalidatePath(`/${l}/account/profile`);
}

export async function logoutEverywhere(form: FormData) {
  const l = lang(form);
  await authed("customer", l, "/auth/logout-all", { method: "POST" });
  await clearSession("customer");
  redirect(`/${l}/login`);
}
