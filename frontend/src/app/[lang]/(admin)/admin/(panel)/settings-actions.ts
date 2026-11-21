"use server";

import { revalidatePath, updateTag } from "next/cache";
import { authed } from "@/lib/auth/client";
import type { AdminState } from "../actions";
import { bool, fail, id, lang, opt, str } from "./form-data";

// A-18 الإعدادات والفروع (FR-ADM-02، FR-ADM-03، US-40). الإعدادات تُحفظ تبويباً كاملاً، والـ API يحفظ ما تغيّر فقط.

const DAYS = ["sat", "sun", "mon", "tue", "wed", "thu", "fri"] as const;

/** الحقول باسم "key" للقيمة المفردة، و"key.sub" لحقل داخل قيمة مركبة، و"key[]" لقائمة */
export async function saveSettings(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const types = JSON.parse(str(form, "_types") || "{}") as Record<string, string>;
  const values: Record<string, unknown> = {};

  for (const [key, type] of Object.entries(types)) {
    const sub = (k: string) => str(form, `${key}.${k}`);
    switch (type) {
      case "bool": values[key] = bool(form, key); break;
      case "days": case "count": values[key] = str(form, key) === "" ? null : Number(str(form, key)); break;
      case "money": case "percent": case "enum": values[key] = opt(form, key); break;
      case "thresholds": values[key] = { limited: Number(sub("limited")), available: Number(sub("available")) }; break;
      case "sla": values[key] = { normal_working_hours: Number(sub("normal_working_hours")), urgent_hours: Number(sub("urgent_hours")) }; break;
      case "business_hours": values[key] = { days: form.getAll(`${key}.days`).map(String), start: sub("start"), end: sub("end") }; break;
      case "company": {
        const fields = ["name_ar", "name_en", "cr_number", "vat_number", "address_ar", "address_en", "phone", "email"];
        values[key] = Object.fromEntries(fields.map((f) => [f, sub(f) || null]));
        break;
      }
      case "governorate_fees": {
        const govs = form.getAll(`${key}._keys`).map(String);
        values[key] = Object.fromEntries(govs.map((g) => [g, sub(g) || null]));
        break;
      }
    }
  }

  const r = await authed("admin", l, "/admin/settings", { method: "PUT", body: { values } });
  if (!r.ok) return fail(r.error);
  revalidatePath(`/${l}/admin/settings`);
  return { ok: true };
}

export async function saveBranch(_prev: AdminState, form: FormData): Promise<AdminState> {
  const l = lang(form);
  const existing = str(form, "id");
  const hours = Object.fromEntries(DAYS.map((d) => [d, bool(form, `open_${d}`) ? { open: str(form, `${d}.open`), close: str(form, `${d}.close`) } : null]));
  const body = {
    ...(existing ? {} : { code: str(form, "code").toUpperCase() }),
    name_ar: str(form, "name_ar"), name_en: str(form, "name_en"), governorate: str(form, "governorate"), wilayat: str(form, "wilayat"),
    address_ar: opt(form, "address_ar"), address_en: opt(form, "address_en"),
    latitude: Number(str(form, "latitude")), longitude: Number(str(form, "longitude")),
    phone: opt(form, "phone"), whatsapp_sales: opt(form, "whatsapp_sales"), whatsapp_service: opt(form, "whatsapp_service"),
    whatsapp_parts: opt(form, "whatsapp_parts"), email: opt(form, "email"),
    working_hours: hours, covered_governorates: form.getAll("covered_governorates").map(String), is_active: bool(form, "is_active"),
  };
  const r = await authed("admin", l, existing ? `/admin/branches/${id(form)}` : "/admin/branches", { method: existing ? "PUT" : "POST", body });
  if (!r.ok) return fail(r.error);
  updateTag("branches");
  revalidatePath(`/${l}/admin/settings`);
  return { ok: true };
}
