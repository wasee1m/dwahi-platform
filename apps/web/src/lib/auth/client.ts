import "server-only";
import { headers } from "next/headers";
import { redirect } from "next/navigation";
import type { Locale } from "@/i18n/config";
import type { components } from "@/lib/api-schema";
import { accessToken, type Area } from "./session";

// استدعاء الـ API باسم المستخدم من خادم Next.js. الأنواع من العقد المولّد (openapi/openapi.json).
export type Me = components["schemas"]["MeResource"];

export type ApiErrorBody = {
  code: string;
  message: string;
  details?: Record<string, unknown> & { retry_after?: number; attempts_left?: number };
  trace_id?: string;
};

export type ApiResult<T> = { ok: true; status: number; data: T } | { ok: false; status: number; error: ApiErrorBody };

const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

/**
 * الـ API يرى خادم Next.js لا المتصفح، فنمرر عنوان المستخدم ومتصفحه، ليظهرا صحيحين في "الأجهزة والجلسات"
 * وسجل التدقيق (US-07، FR-ADM-04). Laravel يثق بهذه الترويسات من الخوادم الموثوقة فقط (TRUSTED_PROXIES).
 */
async function clientHeaders(): Promise<Record<string, string>> {
  try {
    const h = await headers();
    const ip = h.get("x-forwarded-for")?.split(",")[0]?.trim() ?? h.get("x-real-ip");
    return { ...(ip ? { "X-Forwarded-For": ip } : {}), ...(h.get("user-agent") ? { "User-Agent": h.get("user-agent")! } : {}) };
  } catch {
    return {};
  }
}

export async function api<T>(path: string, opts: { method?: string; body?: unknown; token?: string | null; locale: Locale }): Promise<ApiResult<T>> {
  const res = await fetch(`${API_URL}${path}`, {
    method: opts.method ?? "GET",
    headers: {
      ...(await clientHeaders()),
      Accept: "application/json",
      "Accept-Language": opts.locale,
      ...(opts.body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(opts.token ? { Authorization: `Bearer ${opts.token}` } : {}),
    },
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    cache: "no-store",
  });
  if (res.status === 204) return { ok: true, status: 204, data: undefined as T };
  const json = (await res.json().catch(() => ({}))) as { data?: T; error?: ApiErrorBody };
  if (!res.ok) {
    return { ok: false, status: res.status, error: json.error ?? { code: "INTERNAL_ERROR", message: "" } };
  }
  return { ok: true, status: res.status, data: (json.data ?? json) as T };
}

/**
 * طلب محمي لصفحة في منطقة الحساب أو الإدارة. انتهاء الجلسة يعيد لصفحة الدخول.
 * proxy.ts جدّد الرمز قبل العرض، فـ 401 هنا يعني جلسة ملغاة فعلاً.
 */
export async function authed<T>(area: Area, locale: Locale, path: string, init: { method?: string; body?: unknown } = {}) {
  const token = await accessToken(area);
  const result = await api<T>(path, { ...init, token, locale });
  if (!result.ok && result.status === 401) {
    redirect(area === "admin" ? `/${locale}/admin/login?expired=1` : `/${locale}/login?expired=1`);
  }
  return result;
}

export async function currentUser(area: Area, locale: Locale): Promise<Me> {
  const result = await authed<Me>(area, locale, "/me");
  if (!result.ok) throw new Error(`GET /me failed: ${result.error.code}`);
  return result.data;
}
