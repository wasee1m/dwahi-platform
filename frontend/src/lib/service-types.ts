import type { Money } from "./types";

// أنواع طلب الصيانة وأمر العمل كما يعيدها الـ API (ServicePresenter)
export type ServiceStatus = "submitted" | "triaged" | "awaiting_approval" | "approved" | "in_service" | "completed" | "closed" | "cancelled" | "declined";
export type WorkOrderStatus = "unassigned" | "scheduled" | "in_progress" | "on_hold" | "work_completed" | "verified" | "closed" | "cancelled";
export type CustomerStatusKey = ServiceStatus | "awaiting_dropoff" | "additional_awaiting" | "scheduling" | "scheduled" | "in_progress" | "on_hold" | "final_review";

export type WorkOrderRow = {
  id: string; number: string; type: "inspection" | "repair" | "preventive" | "warranty"; status: WorkOrderStatus;
  scheduled_start: string | null; scheduled_end: string | null; on_hold_reason: string | null;
  technicians: { id: string; name: string; role: "lead" | "assistant" }[]; instructions: string | null; cancel_reason: string | null;
};

export type ServiceRow = {
  id: string; number: string; status: ServiceStatus; customer_status: { key: CustomerStatusKey; date: string | null; reason: string | null };
  urgency: "normal" | "urgent"; source: string;
  equipment: { id: string; name: string; serial_number: string | null } | null; component_type: string | null; component_details: string | null;
  preferred_date: string | null; received: boolean; account: { id: string; name: string } | null;
  customer: { id: string | null; name: string | null; phone: string | null; organization: string | null } | null;
  branch: { id: string; name: string | null } | null; sla_due_at: string | null; created_at: string | null;
};

export type ServiceDetail = ServiceRow & {
  problem_description: string | null; inspection_fee: Money | null; route: string | null; triaged_at: string | null;
  decline_reason: string | null; cancel_reason: string | null;
  branch_detail: { name: string | null; address: string | null; phone: string | null; whatsapp: string | null } | null;
  attachments: { id: string; kind: "photo" | "video" | "voice"; mime_type: string; url: string | null }[];
  intake: { number: string; received_at: string | null; receipt_url: string | null } | null;
  estimate: { rfq_id: string; rfq_status: string; quotation: { id: string; number: string | null; status: string; total: Money | null } | null } | null;
  work_orders: WorkOrderRow[];
  can_cancel: boolean;
  equipment_history?: { id: string; number: string; status: ServiceStatus; created_at: string | null }[];
};

export type ServiceMeta = {
  inspection_fee: Money | null; sla: { normal_working_hours: number; urgent_hours: number };
  expected: { normal: string; urgent: string }; components: string[];
  workshop: { name: string | null; address: string | null; phone: string | null; whatsapp: string | null } | null;
};

export const SERVICE_COLOR: Record<string, string> = {
  submitted: "var(--status-neutral)", triaged: "var(--status-progress)", awaiting_approval: "var(--status-waiting)", approved: "var(--status-progress)",
  in_service: "var(--status-active)", completed: "var(--status-success)", closed: "var(--status-closed)", cancelled: "var(--status-closed)",
  declined: "var(--status-negative)", awaiting_dropoff: "var(--status-waiting)", additional_awaiting: "var(--status-waiting)", scheduling: "var(--status-progress)",
  scheduled: "var(--status-progress)", in_progress: "var(--status-active)", on_hold: "var(--status-hold)", final_review: "var(--status-active)",
  unassigned: "var(--status-neutral)", work_completed: "var(--status-active)", verified: "var(--status-success)",
};
