import type { Money } from "@/lib/types";

// A-05: شكل الطلب في لوحة الإدارة (RfqPresenter بعرض الموظف)
export type RfqStatus = "submitted" | "in_review" | "quoted" | "won" | "lost" | "cancelled";
export type AdminRfqRow = {
  id: string; number: string; type: "equipment" | "parts" | "service"; status: RfqStatus; source: string; summary: string[]; items_count: number | null;
  quotation: { id: string; number: string | null; status: string; version: number; total: Money; valid_until: string | null } | null;
  customer: { id: string; name: string | null; phone: string | null; organization: string | null };
  assigned_to: { id: string; name: string } | null; lost_reason: string | null; created_at: string;
};

export const STATUS_COLOR: Record<RfqStatus, string> = {
  submitted: "var(--status-waiting)", in_review: "var(--status-progress)", quoted: "var(--status-active)",
  won: "var(--status-success)", lost: "var(--status-closed)", cancelled: "var(--status-neutral)",
};

/** العمر منذ الإنشاء: ساعات لأول يومين، ثم أيام (لمتابعة "الرد خلال يوم عمل" في US-32) */
export function age(iso: string): { unit: "hours" | "days"; n: number } {
  const h = Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 3_600_000));
  return h < 48 ? { unit: "hours", n: h } : { unit: "days", n: Math.floor(h / 24) };
}
