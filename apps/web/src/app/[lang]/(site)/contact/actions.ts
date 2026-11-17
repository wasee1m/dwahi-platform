"use server";

import { hasLocale } from "@/i18n/config";
import { postLead } from "@/lib/api";

export type LeadState = { status: "idle" | "success" | "error"; fieldErrors?: Record<string, string[]> };

// إرسال الاستفسار (FR-CMS-05). الـ API يتحقق من الحقول ويحد معدل الإرسال (NFR-09).
export async function sendLead(_prev: LeadState, form: FormData): Promise<LeadState> {
  const lang = String(form.get("lang"));
  const field = (k: string) => String(form.get(k) ?? "").trim();
  const body: Record<string, string> = {
    name: field("name"),
    phone: field("phone"),
    email: field("email"),
    company: field("company"),
    topic: field("topic"),
    message: field("message"),
    branch_code: field("branch_code"),
    source_page: field("source_page"),
    website: field("website"),
  };
  for (const k of Object.keys(body)) if (body[k] === "") delete body[k];

  try {
    const result = await postLead(hasLocale(lang) ? lang : "ar", body);
    return result.ok ? { status: "success" } : { status: "error", fieldErrors: result.fieldErrors };
  } catch {
    return { status: "error" };
  }
}
