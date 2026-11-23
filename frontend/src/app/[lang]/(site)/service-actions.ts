"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { hasLocale, type Locale } from "@/i18n/config";
import { authed, type ApiErrorBody } from "@/lib/auth/client";

// طلبات الصيانة للعميل (C-15، C-16، FR-SRV-01)
export type ServiceState = { ok?: boolean; error?: ApiErrorBody; fieldErrors?: Record<string, string[]> };

const toLocale = (l: string): Locale => (hasLocale(l) ? l : "ar");
const fail = (error: ApiErrorBody): ServiceState => ({ error, fieldErrors: error.code === "VALIDATION_FAILED" ? (error.details as Record<string, string[]>) : undefined });

export type ServicePayload = {
  organization_id: string | null; customer_equipment_id: string | null;
  new_equipment: { brand_text: string; model_text: string; serial_number: string } | null;
  component_type: string | null; component_details: string | null; problem_description: string | null; voice_attachment_id: string | null;
  attachments: { id: string; kind: "photo" | "video" }[]; urgency: "normal" | "urgent"; preferred_date: string | null; fees_acknowledged: boolean;
};

export async function createServiceRequest(locale: string, payload: ServicePayload): Promise<ServiceState> {
  const l = toLocale(locale);
  const r = await authed<{ id: string }>("customer", l, "/me/service-requests", { method: "POST", body: payload });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  redirect(`/${l}/account/service/${r.data.id}?sent=1`);
}

export async function cancelServiceRequest(_prev: ServiceState, form: FormData): Promise<ServiceState> {
  const l = toLocale(String(form.get("lang")));
  const id = String(form.get("id") ?? "");
  if (!/^[0-9a-f-]{36}$/i.test(id)) return {};
  const r = await authed("customer", l, `/me/service-requests/${id}/cancel`, { method: "POST", body: { reason: String(form.get("reason") ?? "").trim() || null } });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/account`, "layout");
  return { ok: true };
}
