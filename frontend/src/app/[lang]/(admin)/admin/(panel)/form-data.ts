import "server-only";
import { hasLocale, type Locale } from "@/i18n/config";
import type { ApiErrorBody } from "@/lib/auth/client";
import type { AdminState } from "../actions";

// قراءة حقول النماذج في Server Actions للوحة الإدارة

export const lang = (form: FormData): Locale => {
  const l = String(form.get("lang"));
  return hasLocale(l) ? l : "ar";
};
export const str = (form: FormData, k: string) => String(form.get(k) ?? "").trim();
export const opt = (form: FormData, k: string) => str(form, k) || null;
export const num = (form: FormData, k: string) => (str(form, k) === "" ? null : Number(str(form, k)));
export const bool = (form: FormData, k: string) => form.get(k) === "on";
export const id = (form: FormData) => encodeURIComponent(str(form, "id"));

/** "أ | ب | ج" سطراً سطراً، مع تجاهل الأسطر الفارغة */
export function lines(text: string): string[][] {
  return text.split(/\r?\n/).map((l) => l.split("|").map((c) => c.trim())).filter((c) => c.some(Boolean));
}

export function fail(error: ApiErrorBody): AdminState {
  return { error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined };
}

/** وقت محلي من حقل datetime-local بتوقيت مسقط (UTC+4 دون توقيت صيفي) */
export const muscat = (local: string) => (local ? `${local}:00+04:00` : null);
