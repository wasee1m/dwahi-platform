"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { api, type ApiErrorBody, type Me } from "@/lib/auth/client";
import { accessToken, clearSession, saveSession, type TokenPayload } from "@/lib/auth/session";

// دخول العميل وتسجيله (W-14، W-15، FR-AUTH-01، FR-AUTH-02). الرقم بين الخطوتين في كوكي HttpOnly قصيرة،
// لا في الرابط (لا بيانات شخصية في الروابط).
const OTP_COOKIE = "dw_otp";

export type FormState = { error?: ApiErrorBody | { code: string; message?: string }; ok?: boolean };

type OtpCookie = { phone: string; sentAt: number; next?: string; debug?: string };

function locale(form: FormData): Locale {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
}

/** "next" مسار داخلي فقط، وإلا يمكن استغلاله لتوجيه المستخدم لموقع آخر */
export async function safeNext(next: string | undefined | null, lang: Locale, fallback: string): Promise<string> {
  return next && /^\/(ar|en)\/[^/]/.test(next) && !next.startsWith("//") ? next : `/${lang}${fallback}`;
}

async function readOtpCookie(): Promise<OtpCookie | null> {
  const raw = (await cookies()).get(OTP_COOKIE)?.value;
  try {
    return raw ? (JSON.parse(raw) as OtpCookie) : null;
  } catch {
    return null;
  }
}

async function sendCode(phone: string, lang: Locale, next?: string): Promise<FormState> {
  const r = await api<{ status: string; debug_code?: string }>("/auth/otp/request", { method: "POST", body: { phone }, locale: lang });
  if (!r.ok) return { error: r.error };
  (await cookies()).set(OTP_COOKIE, JSON.stringify({ phone, sentAt: Date.now(), next, debug: r.data.debug_code } satisfies OtpCookie), {
    httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/", maxAge: 600,
  });
  return { ok: true };
}

export async function requestCode(_prev: FormState, form: FormData): Promise<FormState> {
  const lang = locale(form);
  const result = await sendCode(String(form.get("phone") ?? "").trim(), lang, String(form.get("next") ?? "") || undefined);
  if (result.error) return result;
  redirect(`/${lang}/login/verify`);
}

export async function resendCode(_prev: FormState, form: FormData): Promise<FormState> {
  const otp = await readOtpCookie();
  if (!otp) redirect(`/${locale(form)}/login`);
  return sendCode(otp.phone, locale(form), otp.next);
}

export async function verifyCode(_prev: FormState, form: FormData): Promise<FormState> {
  const lang = locale(form);
  const otp = await readOtpCookie();
  if (!otp) redirect(`/${lang}/login`);

  const r = await api<TokenPayload & { user: Me }>("/auth/otp/verify", {
    method: "POST", locale: lang,
    body: { phone: otp.phone, code: String(form.get("code") ?? "").replace(/\D/g, ""), client: "web", remember: form.get("remember") === "on" },
  });
  if (!r.ok) return { error: r.error };

  await saveSession("customer", r.data);
  (await cookies()).delete(OTP_COOKIE);
  redirect(r.data.user.needs_onboarding ? `/${lang}/onboarding` : await safeNext(otp.next, lang, "/account"));
}

export async function emailLogin(_prev: FormState, form: FormData): Promise<FormState> {
  const lang = locale(form);
  const r = await api<TokenPayload & { user: Me }>("/auth/password/login", {
    method: "POST", locale: lang,
    body: { email: String(form.get("email") ?? ""), password: String(form.get("password") ?? ""), client: "web", remember: form.get("remember") === "on" },
  });
  if (!r.ok) return { error: r.error };
  await saveSession("customer", r.data);
  redirect(r.data.user.needs_onboarding ? `/${lang}/onboarding` : await safeNext(String(form.get("next") ?? ""), lang, "/account"));
}

export async function logout(form: FormData) {
  const lang = locale(form);
  const token = await accessToken("customer");
  if (token) await api("/auth/logout", { method: "POST", token, locale: lang });
  await clearSession("customer");
  redirect(`/${lang}`);
}

export async function pendingOtp() {
  return readOtpCookie();
}
