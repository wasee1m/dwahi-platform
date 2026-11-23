import { intlLocale, type Locale } from "@/i18n/config";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { ServiceRow } from "@/lib/service-types";

/** تسمية الحالة للعميل (02 SM-4): الموعد والسبب داخل النص */
export function statusText(cs: ServiceRow["customer_status"], s: AppDictionary["service"], lang: Locale): string {
  const label = s.customerStatuses[cs.key as keyof typeof s.customerStatuses] ?? cs.key;
  const date = cs.date ? new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" }).format(new Date(cs.date)) : "";
  const reason = cs.reason ? (s.holdReasons[cs.reason as keyof typeof s.holdReasons] ?? cs.reason) : "";
  return label.replace("{date}", date).replace("{reason}", reason);
}
