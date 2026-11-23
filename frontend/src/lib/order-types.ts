import type { Availability, Money } from "./types";

// أنواع السلة والطلبات والفواتير كما يعيدها الـ API (CartService، OrderPresenter، InvoicePresenter)
export type OrderStatus = "pending_payment" | "confirmed" | "processing" | "ready_for_pickup" | "out_for_delivery" | "delivered" | "cancelled";
export type ReturnStatus = "requested" | "approved" | "rejected" | "received" | "refunded";
export type InvoiceStatus = "draft" | "issued" | "partially_paid" | "paid" | "void";
export type PayMethod = "cash" | "pos_card" | "bank_transfer" | "cheque" | "card";

export type CartItem = {
  part: { id: string; part_number: string; name: string | null; brand: string; unit: string; is_returnable: boolean; availability: Availability; cover_image_url: string | null };
  quantity: string; unit_price: Money | null; previous_price: Money | null; problem: "inactive" | "insufficient" | null; line_subtotal: Money | null;
};

export type Cart = {
  items: CartItem[];
  totals: { subtotal: Money | null; vat_total: Money | null; total: Money | null };
  has_changes: boolean; can_checkout: boolean;
};

export type CheckoutPreview = {
  cart: Cart;
  fulfillment: "delivery" | "pickup" | null;
  branch_id: string | null;
  totals: { subtotal: Money | null; vat_total: Money | null; delivery_fee: Money | null; total: Money | null };
  needs_phone_confirmation: boolean;
  problem: string | null;
  open_orders: { count: number; limit: number; blocked: boolean };
  branches: { id: string; name: string | null; address: string | null; can_fulfill: boolean }[];
  delivery_fees: Record<string, Money | null>;
};

export type OrderRow = {
  id: string; number: string; status: OrderStatus; fulfillment: "delivery" | "pickup"; items_count: number | null; summary: string[];
  total: Money | null; branch: { id: string; name: string | null } | null; account: { id: string; name: string } | null;
  customer: { id: string | null; name: string | null; phone: string | null; organization: string | null } | null;
  needs_phone_confirmation: boolean; pickup_deadline_at: string | null; from_quote: boolean; created_at: string | null;
};

export type ReturnRow = {
  id: string; status: ReturnStatus; reason: string; decision_note: string | null; created_at: string | null;
  items: { order_item_id: string; name: string; part_number: string | null; quantity: string; restocked: boolean | null }[];
  photos: string[]; order: { id: string; number: string } | null;
  amount?: { subtotal: string; vat_total: string; total: string };
};

export type OrderLine = {
  id: string; line_no: number; part_id: string; part_number: string; name: string; unit: string; quantity: string;
  unit_price: Money | null; discount: Money | null; vat_rate: string; line_subtotal: Money | null; line_total: Money | null;
  is_returnable: boolean; warranty_months: number | null; returnable_quantity: string;
};

export type OrderDetail = OrderRow & {
  payment_method: string; accepted_methods: string[];
  branch_detail: { id: string; name: string | null; address: string | null; phone: string | null; whatsapp: string | null } | null;
  delivery_address: { text: string | null; governorate: string | null; contact_name: string | null; contact_phone: string | null } | null;
  items: OrderLine[];
  totals: { subtotal: Money | null; delivery_fee: Money | null; vat_total: Money | null; total: Money | null; paid: Money | null; balance: Money | null };
  pickup_code: string | null;
  phone_confirmation: { confirmed_at: string | null; note: string | null } | null;
  notes: string | null; cancel_reason: string | null; delivered_at: string | null;
  timeline: { status: string; note: string | null; at: string | null; by: string | null }[];
  can_cancel: boolean; can_return: boolean; return_until: string | null;
  returns: ReturnRow[];
  invoice: { id: string; number: string | null; status: string; pdf_url: string | null } | null;
  payments: { id: string; method: PayMethod; status: string; amount: Money | null; reference: string | null; by: string | null; at: string | null }[];
};

export type CreditNoteRow = { id: string; number: string; reason: string; total: Money | null; issued_at: string | null; pdf_url: string | null; invoice: { id: string; number: string | null } | null };

export type InvoiceRow = {
  id: string; number: string | null; status: InvoiceStatus; overdue: boolean;
  source: { type: "sales_order" | "quotation" | "service_request"; id: string | null; number: string | null };
  issue_date: string | null; due_date: string | null; total: Money | null; paid: Money | null; balance: Money | null; pdf_url: string | null;
  account: { id: string; name: string } | null; customer: { id: string | null; name: string | null; phone: string | null } | null;
  credit_notes: CreditNoteRow[];
};

export type PaymentRow = {
  id: string; method: PayMethod; status: string; amount: Money | null; refunded: Money | null; reference: string | null; received_at: string | null;
  by: string | null; branch: string | null; invoice: { id: string; number: string | null } | null; order: { id: string; number: string } | null;
  refunds: { id: string; amount: Money | null; method: string; reason: string; reference: string | null; at: string | null }[];
};

export type InvoiceDetail = InvoiceRow & {
  seller: Record<string, string | null>; buyer: Record<string, string | null>;
  subtotal: Money | null; discount_total: Money | null; vat_total: Money | null; void_reason: string | null;
  items: { line_no: number; item_type: string; description: string; quantity: string; unit: string; unit_price: Money | null; discount: Money | null; vat_rate: string; line_vat: Money | null; line_total: Money | null; warranty_until: string | null }[];
  payments: PaymentRow[];
};

export const ORDER_COLOR: Record<OrderStatus, string> = {
  pending_payment: "var(--status-neutral)", confirmed: "var(--status-progress)", processing: "var(--status-active)",
  ready_for_pickup: "var(--status-waiting)", out_for_delivery: "var(--status-active)", delivered: "var(--status-success)", cancelled: "var(--status-closed)",
};

export const INVOICE_COLOR: Record<InvoiceStatus, string> = {
  draft: "var(--status-neutral)", issued: "var(--status-progress)", partially_paid: "var(--status-waiting)", paid: "var(--status-success)", void: "var(--status-closed)",
};
