// A-15: نوع صف الاستفسار ولون حالته، مشترك بين القائمة والتفاصيل
export type LeadRow = {
  id: string; name: string; phone: string; company: string | null; topic: "equipment" | "parts" | "service" | "other";
  status: "new" | "contacted" | "qualified" | "closed"; branch: string | null; assigned_to: { id: string; name: string } | null; created_at: string;
};

export const LEAD_COLOR: Record<LeadRow["status"], string> = {
  new: "var(--status-waiting)", contacted: "var(--status-progress)", qualified: "var(--status-success)", closed: "var(--status-closed)",
};
