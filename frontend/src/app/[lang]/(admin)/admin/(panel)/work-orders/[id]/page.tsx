import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney, listSep } from "@/lib/format";
import type { Money } from "@/lib/types";
import { SERVICE_COLOR, type ServiceRow, type WorkOrderCompletion, type WorkOrderRow } from "@/lib/service-types";
import { AdditionalActions, VerifyActions } from "../CompletionForms";
import { CancelWorkOrder, ScheduleForm } from "../ScheduleForm";

// A-09 تفاصيل أمر العمل: الخط الزمني للحالات، والطلب والاستلام، والتقدير المعتمد، والجدولة والإلغاء
type Props = PageProps<"/[lang]/admin/work-orders/[id]">;
type Detail = WorkOrderRow & {
  service_request: ServiceRow & { problem_description: string | null };
  intake: { number: string; received_at: string | null } | null;
  quotation: { id: string; number: string | null; total: Money | null } | null;
  events: { from: string; to: string; reason: string | null; by: string | null; at: string | null }[];
  execution: {
    worked_seconds: number;
    on_hold_reason: string | null;
    photos: { id: string; phase: string; caption: string | null; url: string | null; by: string | null; from_previous_technician: boolean; at: string }[];
    parts: { id: string; part_number: string | null; name: string | null; quantity: string; source: string; unit_price: Money | null; removed: boolean; by: string | null }[];
    notes: { id: string; body: string | null; by: string | null; from_previous_technician: boolean; at: string }[];
    readings: { reading: number; needs_review: boolean; at: string | null }[];
  };
  completion: WorkOrderCompletion;
  approved_scope: { description: string; quantity: string; total: Money }[];
};

/** ساعات:دقائق */
function duration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, "0")}`;
}
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
            {w.technicians.length > 0 && <p className="mt-2">{a.technicians}: {w.technicians.map((x) => `${x.name}${x.role === "lead" ? ` (${a.lead})` : ""}`).join(listSep(lang))}</p>}
          </section>
          <Execution w={w} a={a} time={time} lang={lang} />
          <Completion w={w} a={a} time={time} lang={lang} />
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
          {w.status === "work_completed" && <VerifyActions lang={lang} t={a} id={w.id} />}
          {["unassigned", "scheduled", "on_hold"].includes(w.status) && <CancelWorkOrder lang={lang} t={a} id={w.id} />}
          {w.cancel_reason && <p className="rounded-sm bg-sand-200 p-3 text-sm">{a.cancelReason}: {w.cancel_reason}</p>}
        </aside>
      </div>
    </>
  );
}

type Dict = Awaited<ReturnType<typeof getAppDictionary>>["admin"]["service"];

/** ما سجله الفريق من تطبيق الفني: الوقت والصور بمراحلها والقطع والملاحظات والعداد */
function Execution({ w, a, time, lang }: { w: Detail; a: Dict; time: Intl.DateTimeFormat; lang: "ar" | "en" }) {
  const x = w.execution;
  const empty = !x.worked_seconds && !x.photos.length && !x.parts.length && !x.notes.length && !x.readings.length;
  return (
    <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
      <h2 className="text-lg">{a.execution}</h2>
      {empty ? <p className="mt-2 text-ink-muted">{a.nothingYet}</p> : (
        <div className="mt-3 space-y-4">
          <p>{a.workedTime}: <b className="num" dir="ltr">{duration(x.worked_seconds)}</b>
            {w.status === "on_hold" && x.on_hold_reason && ` · ${a.holdReason}: ${a.holdReasons[x.on_hold_reason as keyof typeof a.holdReasons] ?? x.on_hold_reason}`}</p>
          {x.photos.length > 0 && (
            <div>
              <h3 className="font-semibold">{a.photos}</h3>
              <ul className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                {x.photos.map((p) => (
                  <li key={p.id} className="text-xs">
                    {p.url
                      // eslint-disable-next-line @next/next/no-img-element -- رابط موقّع مؤقت من الـ API، لا يُحسَّن
                      ? <a href={p.url} target="_blank" rel="noreferrer"><img src={p.url} alt={a.phases[p.phase as keyof typeof a.phases] ?? p.phase} className="aspect-square w-full rounded-sm object-cover" /></a>
                      : <div className="aspect-square rounded-sm bg-sand-200" />}
                    <span className="block">{a.phases[p.phase as keyof typeof a.phases] ?? p.phase}{p.caption && ` · ${p.caption}`}</span>
                    <span className="block text-ink-muted">{p.by}{p.from_previous_technician && ` · ${a.fromPrevious}`}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {x.parts.length > 0 && (
            <div>
              <h3 className="font-semibold">{a.partsUsed}</h3>
              <ul className="mt-1 space-y-1">
                {x.parts.map((p) => (
                  <li key={p.id} className={p.removed ? "text-ink-muted line-through" : ""}>
                    {p.part_number && <span className="num" dir="ltr">{p.part_number} · </span>}{p.name} × <span className="num">{p.quantity}</span>
                    {" · "}{a.partSources[p.source as keyof typeof a.partSources] ?? p.source}
                    {p.unit_price && ` · ${formatMoney(p.unit_price, lang)}`}{p.removed && ` · ${a.removedPart}`}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {x.readings.length > 0 && (
            <div>
              <h3 className="font-semibold">{a.hourReadings}</h3>
              <ul className="mt-1">
                {x.readings.map((r, n) => (
                  <li key={n}><span className="num" dir="ltr">{r.reading}</span>{r.at && ` · ${time.format(new Date(r.at))}`}{r.needs_review && <b className="text-danger"> · {a.needsReview}</b>}</li>
                ))}
              </ul>
            </div>
          )}
          {x.notes.length > 0 && (
            <div>
              <h3 className="font-semibold">{a.internalNotes}</h3>
              <ul className="mt-1 space-y-1">
                {x.notes.map((n) => (
                  <li key={n.id}>{n.body}<span className="block text-xs text-ink-muted">{n.by} · {time.format(new Date(n.at))}{n.from_previous_technician && ` · ${a.fromPrevious}`}</span></li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </section>
  );
}

/** US-34: التقرير والاختبارات وقائمة BR-07، ونطاق التقدير المعتمد للمقارنة، والعمل الإضافي بقراره */
function Completion({ w, a, time, lang }: { w: Detail; a: Dict; time: Intl.DateTimeFormat; lang: "ar" | "en" }) {
  const c = w.completion;
  if (!c.report && !c.tests.length && !c.additional.length && !c.checklist) return null;
  const label = (f: { label_ar: string; label_en: string }) => (lang === "en" ? f.label_en : f.label_ar);
  return (
    <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
      <h2 className="text-lg">{a.completion}</h2>
      <div className="mt-3 space-y-4">
        {c.checklist && (
          <div>
            <h3 className="font-semibold">{a.checklist}</h3>
            <ul className="mt-1 grid gap-1 sm:grid-cols-2">
              {Object.entries(c.checklist).map(([k, ok]) => (
                <li key={k} className={ok ? "" : "text-danger"}>{ok ? "✓" : "✗"} {a.checkItems[k as keyof typeof a.checkItems] ?? k}</li>
              ))}
            </ul>
          </div>
        )}
        {c.report && (
          <div>
            <h3 className="font-semibold">{a.report}{c.report.submitted_at && <span className="font-normal text-ink-muted"> · {time.format(new Date(c.report.submitted_at))}</span>}</h3>
            <p className="mt-1"><b>{a.findings}:</b> <span className="whitespace-pre-line">{c.report.findings}</span></p>
            {c.report.actions_taken && <p><b>{a.actionsTaken}:</b> <span className="whitespace-pre-line">{c.report.actions_taken}</span></p>}
            {c.report.recommendations && <p><b>{a.recommendations}:</b> {c.report.recommendations}</p>}
            {c.report.no_parts_used && <p>{a.noPartsUsed}</p>}
            {c.report.pdf_url && <a href={c.report.pdf_url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-block underline">{a.reportPdf}</a>}
            {c.no_charge_reason && <p className="mt-1">{a.noChargeDone}: {c.no_charge_reason}</p>}
          </div>
        )}
        {c.tests.length > 0 && (
          <div>
            <h3 className="font-semibold">{a.tests}</h3>
            {c.tests.map((x) => (
              <div key={x.id} className="mt-1">
                <p>{x.template} · <b className={x.result === "pass" ? "text-success" : "text-danger"}>{x.result === "pass" ? a.pass : a.fail}</b>
                  {x.tested_at && <span className="text-ink-muted"> · {time.format(new Date(x.tested_at))}</span>}</p>
                <ul className="text-xs text-ink-muted">
                  {x.fields.map((f) => {
                    const v = x.values[f.key];
                    return <li key={f.key}>{label(f)}: <span className="num" dir="ltr">{typeof v === "boolean" ? (v ? "✓" : "✗") : v ?? "—"} {f.type === "number" ? f.unit ?? "" : ""}</span></li>;
                  })}
                </ul>
              </div>
            ))}
          </div>
        )}
        {w.approved_scope.length > 0 && (
          <div>
            <h3 className="font-semibold">{a.approvedScope}</h3>
            <ul className="mt-1">{w.approved_scope.map((s, n) => <li key={n}>{s.description} × <span className="num">{s.quantity}</span> · {formatMoney(s.total, lang)}</li>)}</ul>
          </div>
        )}
        {c.additional.length > 0 && (
          <div>
            <h3 className="font-semibold">{a.additional}</h3>
            <ul className="mt-1 space-y-3">
              {c.additional.map((x) => (
                <li key={x.id} className="rounded-sm border border-sand-300 p-3">
                  <p className="whitespace-pre-line">{x.description}</p>
                  {x.parts_needed && <p className="text-ink-muted">{x.parts_needed}</p>}
                  <p className="text-xs text-ink-muted">{x.by}{x.requested_at && ` · ${time.format(new Date(x.requested_at))}`}
                    {x.estimated_amount && ` · ${formatMoney(x.estimated_amount, lang)}`}</p>
                  <p className="mt-1 font-semibold">{a.additionalStatuses[x.status]}{x.blocks_work && x.status === "pending" && ` · ${a.blocksWork}`}</p>
                  {x.rejection_reason && <p>{a.rejectReason}: {x.rejection_reason}</p>}
                  {x.quotation && <p><Link href={`/${lang}/admin/quotations/${x.quotation.id}`} className="num underline" dir="ltr">{x.quotation.number ?? a.draft}</Link> · {formatMoney(x.quotation.total, lang)}</p>}
                  {x.status === "pending" && <AdditionalActions lang={lang} t={a} id={x.id} />}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </section>
  );
}
