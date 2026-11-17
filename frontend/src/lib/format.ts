import type { Locale } from "@/i18n/config";
import type { Money } from "./types";

// عرض المال (06 §4.2): "1,250.500 ر.ع." بثلاث خانات (NFR-21). نصي بالكامل، فلا أرقام عائمة.
export function formatMoney(money: Money, locale: Locale): string {
  const [int, frac = ""] = money.amount.split(".");
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  const amount = `${grouped}.${frac.padEnd(3, "0").slice(0, 3)}`;
  const unit = money.currency === "OMR" ? (locale === "ar" ? "ر.ع." : "OMR") : money.currency;
  return locale === "ar" ? `${amount} ${unit}` : `${unit} ${amount}`;
}

export function formatNumber(value: number): string {
  return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// روابط واتساب (FR-MSG-02): الرقم بصيغة E.164 دون "+"
export function whatsappLink(e164: string, text?: string): string {
  const number = e164.replace(/[^\d]/g, "");
  return `https://wa.me/${number}${text ? `?text=${encodeURIComponent(text)}` : ""}`;
}

export function telLink(e164: string): string {
  return `tel:${e164.replace(/[^\d+]/g, "")}`;
}

// عرض الهاتف المحلي: +968 9553 4404
export function formatPhone(e164: string): string {
  const m = e164.match(/^\+968(\d{4})(\d{4})$/);
  return m ? `+968 ${m[1]} ${m[2]}` : e164;
}
