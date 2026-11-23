"use server";

import { cookies } from "next/headers";
import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import QRCode from "qrcode";
import { hasLocale, type Locale } from "@/i18n/config";
import { api, authed, type ApiErrorBody, type Me } from "@/lib/auth/client";
import { accessToken, clearSession, saveSession, type TokenPayload } from "@/lib/auth/session";

// دخول الموظفين (A-21، FR-AUTH-03، NFR-08) وإدارة الموظفين (A-17، FR-ADM-01)
const MFA_COOKIE = "dwa_mfa";

export type AdminState = {
  error?: ApiErrorBody;
  fieldErrors?: Record<string, string[]>;
  step?: "password" | "totp";
  setup?: { qrSvg: string; secret: string };
  ok?: boolean;
  message?: string;
};

const lang = (form: FormData): Locale => {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
};
const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();
const cookieBase = { httpOnly: true, sameSite: "lax" as const, secure: process.env.NODE_ENV === "production", path: "/" };

export async function staffLogin(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await api<{ mfa_token: string; setup_required: boolean; secret?: string; otpauth_url?: string }>("/auth/staff/login", {
    method: "POST", locale: l, body: { email: str(form, "email"), password: String(form.get("password") ?? "") },
  });
  if (!r.ok) return { step: "password", error: r.error };

  // رمز الخطوة الثانية في كوكي HttpOnly، لا في الصفحة
  (await cookies()).set(MFA_COOKIE, r.data.mfa_token, { ...cookieBase, maxAge: 300 });
  if (r.data.setup_required && r.data.otpauth_url && r.data.secret) {
    const qrSvg = await QRCode.toString(r.data.otpauth_url, { type: "svg", margin: 1, width: 200, color: { dark: "#231F1A", light: "#FFFFFF" } });
    return { step: "totp", setup: { qrSvg, secret: r.data.secret } };
  }
  return { step: "totp" };
}

export async function staffTwoFactor(prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const jar = await cookies();
  const mfa = jar.get(MFA_COOKIE)?.value;
  if (!mfa) return { step: "password", error: { code: "MFA_EXPIRED", message: "" } };

  const r = await api<TokenPayload & { user: Me }>("/auth/staff/2fa", { method: "POST", locale: l, body: { mfa_token: mfa, code: str(form, "code") } });
  if (!r.ok) {
    return r.error.code === "MFA_EXPIRED" ? { step: "password", error: r.error } : { ...prev, step: "totp", error: r.error };
  }
  jar.delete(MFA_COOKIE);
  await saveSession("admin", r.data);
  const next = str(form, "next");
  redirect(/^\/(ar|en)\/admin(\/|$)/.test(next) ? next : `/${l}/admin`);
}

/** خطوتا الدخول بحالة واحدة: انتهاء الخطوة الثانية يعيد للأولى، والدخول الجديد يبدأ من الصفر */
export async function staffAuthStep(prev: AdminState, form: FormData): Promise<AdminState> {
  return str(form, "step") === "totp" ? staffTwoFactor(prev, form) : staffLogin(prev, form);
}

export async function staffLogout(form: FormData) {
  const l = lang(form);
  const token = await accessToken("admin");
  if (token) await api("/auth/logout", { method: "POST", token, locale: l });
  await clearSession("admin");
  redirect(`/${l}/admin/login`);
}

export async function forgotPassword(_prev: AdminState, form: FormData): Promise<AdminState> {
  await api("/auth/password/forgot", { method: "POST", locale: lang(form), body: { email: str(form, "email") } });
  return { ok: true };
}

export async function resetPassword(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await api("/auth/password/reset", {
    method: "POST", locale: l,
    body: { email: str(form, "email"), token: str(form, "token"), password: String(form.get("password") ?? ""), password_confirmation: String(form.get("password_confirmation") ?? "") },
  });
  if (!r.ok) return { error: r.error, fieldErrors: r.error.details as Record<string, string[]> | undefined };
  redirect(`/${l}/admin/login?password_set=1`);
}

// ---------- A-17 الموظفون والأدوار ----------
function fail(error: ApiErrorBody): AdminState {
  return { error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined };
}

export async function createStaff(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, "/admin/staff", {
    method: "POST",
    body: {
      full_name: str(form, "full_name"), email: str(form, "email"), phone: str(form, "phone") || null,
      employee_no: str(form, "employee_no"), branch_code: str(form, "branch_code"),
      roles: form.getAll("roles").map(String), restrict_to_branch: form.get("restrict_to_branch") === "on",
    },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/users`);
  return { ok: true };
}

export async function updateStaffRoles(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const r = await authed("admin", l, `/admin/staff/${encodeURIComponent(str(form, "id"))}`, {
    method: "PATCH",
    body: {
      roles: form.getAll("roles").map(String), branch_code: str(form, "branch_code"), restrict_to_branch: form.get("restrict_to_branch") === "on",
      // مهارات الفني لفلترة لوحة التوزيع (FR-WO-02)، مفصولة بفاصلة
      skills: str(form, "skills").split(/[,،]/).map((s) => s.trim()).filter(Boolean),
    },
  });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/users`);
  return { ok: true };
}

export async function staffAction(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const id = encodeURIComponent(str(form, "id"));
  const op = str(form, "op");
  const paths: Record<string, string> = {
    suspend: `/admin/staff/${id}/suspend`, activate: `/admin/staff/${id}/activate`,
    reset2fa: `/admin/staff/${id}/reset-2fa`, code: `/admin/technicians/${id}/activation-code`,
  };
  if (!paths[op]) return {};
  const r = await authed<{ code?: string }>("admin", l, paths[op], { method: "POST", body: op === "suspend" ? { reason: str(form, "reason") } : {} });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/users`);
  return { ok: true, message: op === "code" ? r.data.code : undefined };
}
