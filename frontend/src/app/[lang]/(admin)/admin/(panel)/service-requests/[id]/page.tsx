import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone, listSep } from "@/lib/format";
import { SERVICE_COLOR, type ServiceDetail } from "@/lib/service-types";
import { serviceStep } from "../../service-actions";
import { DeclineForm, RepairForm, TriageForm } from "../ServiceForms";

// A-08 تفاصيل طلب الصيانة: العميل، والمعدة وسجلها، والعطل ومرفقاته، والاستلام، وإجراءات الفرز والتقدير وأمر الإصلاح
type Props = PageProps<"/[lang]/admin/service-requests/[id]">;

export default function AdminServicePage({ params }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <Detail params={params} />
      </Suspense>
    </div>
  );
}

async function Detail({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const [res, me] = await Promise.all([
    authed<ServiceDetail & { branches: { id: string; name: string }[] }>("admin", lang, `/admin/service-requests/${id}`),
    currentUser("admin", lang),
  ]);
  if (!res.ok) notFound();
  const r = res.data;
  const a = t.admin.service;
  const s = t.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const branches = r.branches;
  const canDesk = me.permissions.includes("workshop.handover");
  const quote = r.estimate?.quotation;

  return (
    <>
      <Link href={`/${lang}/admin/service-requests`} className="text-sm font-semibold text-ink-muted">← {a.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{r.number}</h1>
          <p className="mt-1 text-ink-muted">
            <span className="font-semibold" style={{ color: SERVICE_COLOR[r.status] }}>{a.statuses[r.status]}</span>
            {" · "}{s.urgencies[r.urgency]}{" · "}{a.sources[r.source as keyof typeof a.sources] ?? r.source}
            {r.branch && ` · ${r.branch.name}`}
          </p>
        </div>
        <div className="text-end text-sm">
          {r.customer?.id && <Link href={`/${lang}/admin/customers/${r.customer.id}`} className="font-semibold underline">{r.customer.name}</Link>}
          {r.customer?.organization && <p>{r.customer.organization}</p>}
          {r.customer?.phone && <a href={`tel:${r.customer.phone}`} className="num block" dir="ltr">{formatPhone(r.customer.phone)}</a>}
        </div>
      </div>
      {r.decline_reason && <Notice tone="danger">{s.declined}: {r.decline_reason}</Notice>}
      {r.cancel_reason && <Notice tone="info">{s.cancelReason}: {r.cancel_reason}</Notice>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-6">
          <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
            <h2 className="text-lg">{s.item}</h2>
            <p className="mt-1 font-semibold">{r.equipment?.name ?? s.components[r.component_type as keyof typeof s.components]}
              {r.equipment?.serial_number && <span className="num ms-2 font-normal text-ink-muted" dir="ltr">{r.equipment.serial_number}</span>}</p>
            {r.component_details && <p>{r.component_details}</p>}
            <h3 className="mt-4 font-bold">{s.problem}</h3>
            <p className="whitespace-pre-line">{r.problem_description ?? s.voiceOnly}</p>
            {r.attachments.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-3">
                {r.attachments.map((x) => x.kind === "voice"
                  ? <audio key={x.id} controls src={x.url ?? undefined} className="h-10" />
                  : x.kind === "video"
                    ? <video key={x.id} controls src={x.url ?? undefined} className="h-40 rounded-sm bg-black" />
                    // eslint-disable-next-line @next/next/no-img-element
                    : <a key={x.id} href={x.url ?? "#"} target="_blank" rel="noopener noreferrer"><img src={x.url ?? ""} alt="" className="h-24 rounded-sm object-cover" /></a>)}
              </div>
            )}
            <p className="mt-3 text-ink-muted">{s.preferredDate}: <span className="num">{r.preferred_date ?? "—"}</span>
              {r.inspection_fee && <> · {s.inspectionFee}: <span className="num">{formatMoney(r.inspection_fee, lang)}</span></>}</p>
          </section>

          <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
            <h2 className="text-lg">{a.workOrders}</h2>
            {r.work_orders.length === 0 ? <p className="mt-2 text-ink-muted">{t.admin.common.none}</p> : (
              <ul className="mt-2 divide-y divide-sand-300">
                {r.work_orders.map((w) => (
                  <li key={w.id} className="flex flex-wrap justify-between gap-2 py-2">
                    <span><Link href={`/${lang}/admin/work-orders/${w.id}`} className="num font-semibold underline" dir="ltr">{w.number}</Link> · {s.woTypes[w.type]}
                      {w.technicians.length > 0 && ` · ${w.technicians.map((x) => x.name).join(listSep(lang))}`}</span>
                    <span><span style={{ color: SERVICE_COLOR[w.status] }} className="font-semibold">{a.woStatuses[w.status]}</span>
                      {w.scheduled_start && ` · ${time.format(new Date(w.scheduled_start))}`}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {r.equipment_history && r.equipment_history.length > 0 && (
            <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
              <h2 className="text-lg">{a.history}</h2>
              <ul className="mt-2 space-y-1">
                {r.equipment_history.map((h) => (
                  <li key={h.id}><Link href={`/${lang}/admin/service-requests/${h.id}`} className="num underline" dir="ltr">{h.number}</Link> · {a.statuses[h.status]}</li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <aside className="space-y-4">
          <section className="rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
            <h2 className="text-base">{a.intake}</h2>
            {r.intake ? (
              <p className="mt-1"><span className="num font-semibold" dir="ltr">{r.intake.number}</span>
                {r.intake.received_at && ` · ${time.format(new Date(r.intake.received_at))}`}
                <span className="mt-2 flex gap-3">
                  <Link href={`/${lang}/admin/workshop-desk/label/${r.id}`} className="underline">{a.printLabel}</Link>
                  {r.intake.receipt_url && <a href={r.intake.receipt_url} target="_blank" rel="noopener noreferrer" className="underline">{s.receipt}</a>}
                </span></p>
            ) : (
              <>
                <p className="mt-1 text-ink-muted">{a.notReceived}</p>
                {canDesk && ["submitted", "triaged", "awaiting_approval", "approved", "in_service"].includes(r.status) && (
                  <Link href={`/${lang}/admin/workshop-desk?receive=${r.id}`} className="mt-2 inline-block rounded-sm bg-night px-4 py-2 font-bold text-on-night">{a.receive}</Link>
                )}
              </>
            )}
          </section>

          {r.status === "submitted" && (
            <>
              <TriageForm lang={lang} t={a} id={r.id} urgency={r.urgency} branchId={r.branch?.id ?? branches[0]?.id ?? ""} branches={branches} />
              <DeclineForm lang={lang} t={a} id={r.id} />
            </>
          )}
          {(r.status === "triaged" || (r.status === "in_service" && !r.work_orders.some((w) => w.type === "repair" && w.status !== "cancelled"))) && (
            <section className="space-y-2 rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
              <h2 className="text-base">{a.estimate}</h2>
              {quote && <p>{quote.number ?? a.draft} · {a.quoteStatuses[quote.status as keyof typeof a.quoteStatuses] ?? quote.status}{quote.total && ` · ${formatMoney(quote.total, lang)}`}</p>}
              <ActionButton action={serviceStep} fields={{ lang, id: r.id, op: "estimate" }} label={quote?.status === "draft" ? a.continueEstimate : a.prepareEstimate} look="primary" />
            </section>
          )}
          {r.status === "awaiting_approval" && quote && <Notice tone="info">{a.awaitingCustomer} · {quote.number}</Notice>}
          {r.status === "approved" && <RepairForm lang={lang} t={a} id={r.id} />}
        </aside>
      </div>
    </>
  );
}
