import type { Money } from "@/lib/types";

// أنواع طلب العرض والعرض كما يعيدها الـ API للعميل (RfqPresenter)
export type RfqStatus = "submitted" | "in_review" | "quoted" | "won" | "lost" | "cancelled";
export type QuoteStatus = "draft" | "sent" | "accepted" | "rejected" | "revision_requested" | "expired" | "superseded";

export type RfqRow = {
  account: { id: string; name: string } | null;
  id: string; number: string; type: "equipment" | "parts" | "service"; status: RfqStatus; summary: string[];
  quotation: { id: string; number: string | null; status: QuoteStatus; version: number; total: Money; valid_until: string | null } | null;
  lost_reason: string | null; created_at: string;
};

export type Attachment = { id: string; name: string | null; mime_type: string; url: string | null };

export type QuoteLine = {
  id: string; line_no: number; item_type: string; description_ar: string; description_en: string; quantity: string; unit: string;
  unit_price: string; discount_amount: string; vat_rate: string; line_subtotal: string; line_vat: string; line_total: string; warranty_months: number | null;
};

export type Quotation = {
  id: string; number: string | null; version: number; status: QuoteStatus; valid_until: string | null; acceptable: boolean;
  subtotal: Money; discount_total: Money; vat_total: Money; total: Money;
  estimated_duration: string | null; delivery_terms: string | null; warranty_terms: string | null; payment_terms: string | null;
  sent_at: string | null; responded_at: string | null; response_note: string | null; lpo_number: string | null; approval_requested_at: string | null;
  items: QuoteLine[];
};

export type RfqDetail = RfqRow & {
  equipment: { id: string; name: string; serial_number: string | null } | null;
  notes: string | null; cancel_reason: string | null; delivery_country: string; delivery_address_text: string | null;
  branch: { code: string; name: string; whatsapp_sales: string | null; whatsapp_parts: string | null } | null;
  items: { id: string; line_no: number; title: string; quantity: string; specs: Record<string, string | number> | null; free_text: string | null;
    listing: { id: string; slug: string; status: string; cover_url: string | null } | null;
    part: { id: string; part_number: string; brand: string; brand_slug: string } | null; attachments: Attachment[] }[];
  attachments: Attachment[];
  quotations: Quotation[];
  can_decide: boolean; can_cancel: boolean; can_renew: boolean; unread_messages: number;
};

export const RFQ_COLOR: Record<RfqStatus, string> = {
  submitted: "var(--status-waiting)", in_review: "var(--status-progress)", quoted: "var(--status-active)",
  won: "var(--status-success)", lost: "var(--status-closed)", cancelled: "var(--status-neutral)",
};

/** الأيام المتبقية حتى نهاية يوم الصلاحية بتوقيت مسقط (BR-02). 0 = آخر يوم اليوم. */
export function daysLeft(validUntil: string): number {
  const today = new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Muscat" })).setHours(0, 0, 0, 0);
  return Math.round((Date.parse(`${validUntil}T00:00:00`) - today) / 86_400_000);
}
