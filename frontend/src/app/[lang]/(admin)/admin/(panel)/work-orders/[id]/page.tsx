import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Money } from "@/lib/types";
import { SERVICE_COLOR, type ServiceRow, type WorkOrderRow } from "@/lib/service-types";
import { CancelWorkOrder, ScheduleForm } from "../ScheduleForm";

// A-09 تفاصيل أمر العمل: الخط الزمني للحالات، والطلب والاستلام، والتقدير المعتمد، والجدولة والإلغاء
type Props = PageProps<"/[lang]/admin/work-orders/[id]">;
type Detail = WorkOrderRow & {
  service_request: ServiceRow & { problem_description: string | null };
  intake: { number: string; received_at: string | null } | null;
  quotation: { id: string; number: string | null; total: Money | null } | null;
  events: { from: string; to: string; reason: string | null; by: string | null; at: string | null }[];
};
type Board = { technicians: { id: string; name: string; skills: string[] }[] };

export default function WorkOrderPage({ params }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[600px]" />}>
        <View params={params} />
      </Suspense>
    </div>
  );
}

/** "YYYY-MM-DDTHH:mm" بتوقيت مسقط لحقل datetime-local */
function localMuscat(iso: string | null): string {
  const d = iso ? new Date(iso) : new Date(Date.now() + 86_400_000);
  const m = new Date(d.getTime() + 4 * 3_600_000);
  if (!iso) m.setUTCHours(8, 0, 0, 0);
  return m.toISOString().slice(0, 16);
}

async function View({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const [res, board] = await Promise.all([authed<Detail>("admin", lang, `/admin/work-orders/${id}`), authed<Board>("admin", lang, "/admin/dispatch")]);
  if (!res.ok) notFound();
  const w = res.data;
  const a = t.admin.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const lead = w.technicians.find((x) => x.role === "lead")?.id ?? "";
  const hours = w.scheduled_start && w.scheduled_end ? Math.max(1, Math.round((Date.parse(w.scheduled_end) - Date.parse(w.scheduled_start)) / 3_600_000)) : 4;
  const sr = w.service_request;

  return (
    <>
      <Link href={`/${lang}/admin/work-orders`} className="text-sm font-semibold text-ink-muted">← {a.workOrders}</Link>
      <div>
        <h1 className="num text-3xl" dir="ltr">{w.number}</h1>
        <p className="mt-1 text-ink-muted">{t.service.woTypes[w.type]} · <span className="font-semibold" style={{ color: SERVICE_COLOR[w.status] }}>{a.woStatuses[w.status]}</span>
          {w.scheduled_start && ` · ${time.format(new Date(w.scheduled_start))}`}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="space-y-6">
          <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
            <h2 className="text-lg">{a.request}</h2>
            <p className="mt-1"><Link href={`/${lang}/admin/service-requests/${sr.id}`} className="num font-semibold underline" dir="ltr">{sr.number}</Link>
              {" · "}{sr.customer?.organization ?? sr.customer?.name}</p>
            <p>{sr.equipment?.name ?? t.service.components[sr.component_type as keyof typeof t.service.components]}</p>
            <p className="mt-2 whitespace-pre-line text-ink-muted">{sr.problem_description}</p>
            {w.instructions && <p className="mt-2"><b>{a.instructions}:</b> {w.instructions}</p>}
            <p className="mt-2">{a.intake}: {w.intake ? <span className="num" dir="ltr">{w.intake.number}</span> : a.notReceived}</p>
            {w.quotation && <p>{a.approvedEstimate}: <span className="num" dir="ltr">{w.quotation.number}</span> · {w.quotation.total && formatMoney(w.quotation.total, lang)}</p>}
            {w.technicians.length > 0 && <p className="mt-2">{a.technicians}: {w.technicians.map((x) => `${x.name}${x.role === "lead" ? ` (${a.lead})` : ""}`).join("، ")}</p>}
          </section>
          <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
            <h2 className="text-lg">{a.timeline}</h2>
            <ol className="mt-3 space-y-2 border-s-2 border-sand-300 ps-4">
              {w.events.map((e, n) => (
                <li key={n}><b>{a.woStatuses[e.to as keyof typeof a.woStatuses] ?? e.to}</b>
                  <span className="block text-xs text-ink-muted">{e.at && time.format(new Date(e.at))}{e.by && ` · ${e.by}`}</span>
                  {e.reason && <span className="block text-xs">{e.reason}</span>}</li>
              ))}
            </ol>
          </section>
        </div>
        <aside className="space-y-4">
          {(w.status === "unassigned" || w.status === "scheduled") && (
            <ScheduleForm lang={lang} t={a} id={w.id} technicians={board.ok ? board.data.technicians : []} rescheduling={w.status === "scheduled"}
              initial={{ lead, assistants: w.technicians.filter((x) => x.role === "assistant").map((x) => x.id), start: localMuscat(w.scheduled_start), hours }} />
          )}
          {["unassigned", "scheduled", "on_hold"].includes(w.status) && <CancelWorkOrder lang={lang} t={a} id={w.id} />}
          {w.cancel_reason && <p className="rounded-sm bg-sand-200 p-3 text-sm">{a.cancelReason}: {w.cancel_reason}</p>}
        </aside>
      </div>
    </>
  );
}
