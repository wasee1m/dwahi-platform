"use server";

import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { api, type ApiErrorBody } from "@/lib/auth/client";

// استعادة كلمة مرور العميل بالبريد الموثق (FR-AUTH-04). الرد واحد سواء وُجد الحساب أم لا.
export type RecoveryState = { ok?: boolean; error?: ApiErrorBody; fieldErrors?: Record<string, string[]> };

const locale = (form: FormData): Locale => {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
};
const fail = (error: ApiErrorBody): RecoveryState => ({ error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined });

export async function forgotPassword(_prev: RecoveryState, form: FormData): Promise<RecoveryState> {
  const r = await api("/auth/password/forgot", { method: "POST", locale: locale(form), body: { email: String(form.get("email") ?? "").trim() } });
  return r.ok ? { ok: true } : fail(r.error);
}

export async function resetPassword(_prev: RecoveryState, form: FormData): Promise<RecoveryState> {
  const lang = locale(form);
  const r = await api("/auth/password/reset", {
    method: "POST", locale: lang,
    body: {
      email: String(form.get("email") ?? ""), token: String(form.get("token") ?? ""),
      password: String(form.get("password") ?? ""), password_confirmation: String(form.get("password_confirmation") ?? ""),
    },
  });
  if (!r.ok) return fail(r.error);
  redirect(`/${lang}/login/email`);
}
