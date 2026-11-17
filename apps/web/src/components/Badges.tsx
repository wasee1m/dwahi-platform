import type { Dictionary } from "@/i18n/get-dictionary";
import type { Availability, EquipmentCard } from "@/lib/types";

// شارة الحالة: نص + لون، ولا يُعتمد على اللون وحده (01 §9.2، NFR-15)
function Badge({ color, children }: { color: string; children: React.ReactNode }) {
  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-[999px] bg-sand-0/95 px-2.5 py-1 text-xs font-bold ring-1 ring-inset"
      style={{ color, ["--tw-ring-color" as string]: color }}
    >
      <span className="size-1.5 rounded-full" style={{ background: color }} aria-hidden="true" />
      {children}
    </span>
  );
}

export function ConditionBadge({ item, dict }: { item: Pick<EquipmentCard, "condition" | "status">; dict: Dictionary }) {
  if (item.status === "reserved") return <Badge color="var(--status-waiting)">{dict.equipment.status.reserved}</Badge>;
  if (item.status === "sold") return <Badge color="var(--status-closed)">{dict.equipment.status.sold}</Badge>;
  const color = item.condition === "new" ? "var(--status-success)" : item.condition === "for_parts" ? "var(--status-hold)" : "var(--status-progress)";
  return <Badge color={color}>{dict.equipment.conditions[item.condition]}</Badge>;
}

export function AvailabilityBadge({ level, dict }: { level: Availability; dict: Dictionary }) {
  const color = level === "available" ? "var(--status-success)" : level === "limited" ? "var(--status-waiting)" : "var(--status-neutral)";
  return <Badge color={color}>{dict.parts.availabilityLevels[level]}</Badge>;
}
