"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { authed } from "@/lib/auth/client";
import type { AdminState } from "../actions";
import { fail, lang, muscat, opt, str } from "./form-data";

// A-08 الفرز، A-09 أوامر العمل، A-10 التوزيع، A-22 الاستلام. الـ API هو الحَكَم في الانتقالات (SM-4، SM-5).
const uuid = /^[0-9a-f-]{36}$/i;

/** الفرز والرفض والتقدير وأمر الإصلاح */
export async function serviceStep(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const id = str(form, "id");
  const op = str(form, "op");
  if (!uuid.test(id)) return {};
  if (op === "estimate") {
    const r = await authed<{ quotation_id: string }>("admin", l, `/admin/service-requests/${id}/estimate`, { method: "POST" });
    if (!r.ok) return fail(r.error);
    redirect(`/${l}/admin/quotations/${r.data.quotation_id}`);
  }
  const calls: Record<string, Record<string, unknown>> = {
    triage: { route: str(form, "route"), urgency: str(form, "urgency"), branch_id: str(form, "branch_id"), instructions: opt(form, "instructions") },
    decline: { reason: str(form, "reason") },
    repair: { instructions: opt(form, "instructions") },
  };
  if (!calls[op]) return {};
  const r = await authed("admin", l, `/admin/service-requests/${id}/${op}`, { method: "POST", body: calls[op] });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/service-requests`, "layout");
  return { ok: true, message: op };
}

/** 5.2 و 5.3: الإسناد والنافذة. التعارض يعود برسالته وقائمته، والتأكيد بإعادة الإرسال مع force */
export async function scheduleWorkOrder(_prev: AdminState & { conflicts?: { work_order: string; technician: string }[] }, form: FormData) {
  const l = lang(form);
  const id = str(form, "id");
  if (!uuid.test(id)) return {};
  const start = muscat(str(form, "start"));
  const hours = Number(str(form, "hours") || "4");
  const end = start ? new Date(Date.parse(start) + hours * 3_600_000).toISOString() : null;
  const r = await authed("admin", l, `/admin/work-orders/${id}/schedule`, {
    method: "POST",
    body: {
      lead_id: str(form, "lead_id"), assistant_ids: form.getAll("assistant_ids").map(String).filter((x) => uuid.test(x) && x !== str(form, "lead_id")),
      start, end, force: str(form, "force") === "1", reason: opt(form, "reason"),
    },
  });
  if (!r.ok) {
    const conflicts = r.error.code === "SCHEDULE_CONFLICT" ? (r.error.details as { conflicts?: { work_order: string; technician: string }[] }).conflicts : undefined;
    return { ...fail(r.error), conflicts };
  }
  revalidatePath(`/${l}/admin/work-orders`, "layout");
  revalidatePath(`/${l}/admin/dispatch`);
  return { ok: true };
}

export async function cancelWorkOrder(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const id = str(form, "id");
  if (!uuid.test(id)) return {};
  const r = await authed("admin", l, `/admin/work-orders/${id}/cancel`, { method: "POST", body: { reason: str(form, "reason") } });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/work-orders`, "layout");
  return { ok: true };
}

/** FR-SRV-08: بطاقة الاستلام. الصور والتوقيع رُفعت قبلها عبر staffUpload */
export async function receiveItem(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const id = str(form, "id");
  const meter = str(form, "hour_meter_reading");
  const body = {
    brought_by_name: str(form, "brought_by_name"), brought_by_phone: opt(form, "brought_by_phone"),
    condition_notes: opt(form, "condition_notes"), accessories: opt(form, "accessories"),
    hour_meter_reading: meter === "" ? null : Number(meter), hour_meter_missing_reason: opt(form, "hour_meter_missing_reason"),
    signature_id: str(form, "signature_id"), photo_ids: form.getAll("photo_id").map(String).filter((x) => uuid.test(x)),
  };
  // حضور دون طلب: يُنشأ الطلب ويُستلم معاً (US-48)
  if (str(form, "walk_in") === "1") {
    const r = await authed<{ id: string }>("admin", l, "/admin/service-requests/walk-in", {
      method: "POST",
      body: {
        ...body, customer_id: str(form, "customer_id"), organization_id: null,
        component_type: opt(form, "component_type"), component_details: opt(form, "component_details"),
        new_equipment: opt(form, "model_text") ? { brand_text: opt(form, "brand_text"), model_text: opt(form, "model_text"), serial_number: opt(form, "serial_number") } : null,
        problem_description: str(form, "problem_description"), urgency: str(form, "urgency") || "normal", fees_acknowledged: true,
      },
    });
    if (!r.ok) return fail(r.error);
    redirect(`/${l}/admin/workshop-desk/label/${r.data.id}`);
  }
  if (!uuid.test(id)) return {};
  const r = await authed("admin", l, `/admin/service-requests/${id}/intake`, { method: "POST", body });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/service-requests`, "layout");
  redirect(`/${l}/admin/workshop-desk/label/${id}`);
}

/** فتح الطلب برقم بطاقة الاستلام أو رقم الطلب (مسح ملصق QR) */
export async function findByCard(form: FormData) {
  const l = lang(form);
  const q = str(form, "q").toUpperCase();
  if (/^RC-\d{4}-\d{6}$/.test(q)) {
    const r = await authed<{ service_request_id: string }>("admin", l, `/admin/intakes/${encodeURIComponent(q)}`);
    if (r.ok) redirect(`/${l}/admin/service-requests/${r.data.service_request_id}`);
  }
  redirect(`/${l}/admin/workshop-desk?q=${encodeURIComponent(str(form, "q"))}`);
}
