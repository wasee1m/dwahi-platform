import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { SERVICE_COLOR, type ServiceDetail } from "@/lib/service-types";
import { cancelServiceRequest } from "../../../service-actions";
import { ReviewForm } from "../ReviewForm";
import { statusText } from "../status";

// تفاصيل طلب الصيانة للعميل (C-17): الحالة، والتقدير والتقديرات الإضافية، والاستلام وإيصاله، والموعد، وتقرير الخدمة وصوره
// ونتيجة الاختبار، و"جاهز للاستلام" بالمستحق، وإيصال التسليم والفاتورة، والتقييم (BR-10)، والإلغاء قبل بدء العمل (BR-11)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/service/[id]">;

export default function ServiceDetailPage({ params, searchParams }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[560px]" />}>
        <Detail params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Detail({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang, id }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const res = await authed<ServiceDetail>("customer", lang, `/me/service-requests/${id}`);
  if (!res.ok) notFound();
  const r = res.data;
  const s = t.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const quote = r.estimate?.quotation;
  const c = r.completion;

  return (
    <>
      <Link href={`/${lang}/account/service`} className="text-sm font-semibold text-ink-muted">← {s.title}</Link>
      <div>
        <h1 className="num text-3xl" dir="ltr">{r.number}</h1>
        <p className="mt-1 text-lg font-semibold" style={{ color: SERVICE_COLOR[r.customer_status.key] }}>{statusText(r.customer_status, s, lang)}</p>
        <p className="text-ink-muted">{r.equipment?.name ?? s.components[r.component_type as keyof typeof s.components]}{r.component_details && ` · ${r.component_details}`}</p>
      </div>
      {sp.sent && <Notice tone="success">{s.sent}</Notice>}

      {r.status === "awaiting_approval" && quote && (
        <section className="rounded-md border-2 border-sun bg-sand-0 p-5">
          <p className="font-bold">{s.estimateWaiting}: <span className="num">{quote.total && formatMoney(quote.total, lang)}</span></p>
          <Link href={`/${lang}/account/quotes/${r.estimate!.rfq_id}`} className="mt-3 inline-block rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun">{s.reviewEstimate}</Link>
        </section>
      )}
      {r.status !== "awaiting_approval" && quote && quote.status !== "draft" && (
        <p className="text-sm">{s.estimate}: <span className="num" dir="ltr">{quote.number}</span> · <span className="num">{quote.total && formatMoney(quote.total, lang)}</span>
          {" · "}<Link href={`/${lang}/account/quotes/${r.estimate!.rfq_id}`} className="underline">{s.view}</Link></p>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
          <h2 className="text-lg">{s.problem}</h2>
          <p className="mt-2 whitespace-pre-line">{r.problem_description ?? s.voiceOnly}</p>
          {r.attachments.length > 0 && (
            <ul className="mt-3 flex flex-wrap gap-2">
              {r.attachments.map((a) => (
                <li key={a.id}>
                  {a.kind === "voice" ? <audio controls src={a.url ?? undefined} className="h-10" />
                    : <a href={a.url ?? "#"} target="_blank" rel="noopener noreferrer" className="rounded-sm bg-sand-200 px-2 py-1">{a.kind === "video" ? "🎥" : "📷"} {s.attachmentKinds[a.kind]}</a>}
                </li>
              ))}
            </ul>
          )}
          {r.inspection_fee && <p className="mt-3 text-ink-muted">{s.inspectionFee}: <span className="num">{formatMoney(r.inspection_fee, lang)}</span></p>}
        </section>

        <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
          <h2 className="text-lg">{s.workshop}</h2>
          {r.branch_detail && <p className="mt-2">{r.branch_detail.name}{r.branch_detail.address && ` · ${r.branch_detail.address}`}</p>}
          {r.preferred_date && <p className="mt-1">{s.preferredDate}: <span className="num">{r.preferred_date}</span></p>}
          {r.intake ? (
            <p className="mt-2">{s.receivedCard} <span className="num font-semibold" dir="ltr">{r.intake.number}</span>
              {r.intake.received_at && ` · ${time.format(new Date(r.intake.received_at))}`}
              {r.intake.receipt_url && <> · <a href={r.intake.receipt_url} target="_blank" rel="noopener noreferrer" className="underline">{s.receipt}</a></>}</p>
          ) : r.status !== "cancelled" && r.status !== "declined" && <p className="mt-2 text-ink-muted">{s.bringIt}</p>}
          {r.work_orders.filter((w) => w.scheduled_start && w.status !== "cancelled").map((w) => (
            <p key={w.id} className="mt-1">{s.woTypes[w.type]}: {time.format(new Date(w.scheduled_start!))}</p>
          ))}
        </section>
      </div>

      {c?.pickup && (
        <section className="rounded-md border-2 border-sun bg-sand-0 p-5">
          <p className="text-lg font-bold">{s.readyForPickup}</p>
          <p className="mt-1">{s.amountDue}: <span className="num font-bold">{formatMoney(c.pickup.due, lang)}</span></p>
          <p className="text-sm text-ink-muted">{s.pickupHint}</p>
          {r.branch_detail?.address && <p className="text-sm">{r.branch_detail.name} · {r.branch_detail.address}</p>}
        </section>
      )}
      {c && c.additional.length > 0 && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
          <h2 className="text-lg">{s.additionalEstimates}</h2>
          <ul className="mt-2 space-y-1">
            {c.additional.map((x) => (
              <li key={x.id}>{x.description}{x.quotation && <> · <span className="num">{formatMoney(x.quotation.total, lang)}</span> · {t.admin.service.additionalStatuses[x.status]}
                {x.status === "quoted" && x.rfq_id && <> · <Link href={`/${lang}/account/quotes/${x.rfq_id}`} className="font-semibold underline">{s.reviewEstimate}</Link></>}</>}</li>
            ))}
          </ul>
        </section>
      )}
      {c?.reports.map((rep) => rep.report && (
        <section key={rep.work_order} className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
          <h2 className="text-lg">{s.report} · {s.woTypes[rep.type as keyof typeof s.woTypes] ?? rep.type}{rep.no_charge && ` · ${s.noCharge}`}</h2>
          <p className="mt-2"><b>{s.findings}:</b> <span className="whitespace-pre-line">{rep.report.findings}</span></p>
          {rep.report.actions_taken && <p><b>{s.actionsTaken}:</b> <span className="whitespace-pre-line">{rep.report.actions_taken}</span></p>}
          {rep.report.recommendations && <p><b>{s.recommendations}:</b> {rep.report.recommendations}</p>}
          {rep.report.hour_meter_reading !== null && <p>{s.hourReading}: <span className="num" dir="ltr">{rep.report.hour_meter_reading}</span></p>}
          {rep.tests.map((x) => <p key={x.id}>{s.testResult}: {x.template} · <b>{x.result === "pass" ? s.pass : s.fail}</b></p>)}
          {(["before", "after"] as const).map((ph) => rep[ph].length > 0 && (
            <div key={ph} className="mt-2">
              <p className="font-semibold">{s[ph]}</p>
              <div className="mt-1 flex flex-wrap gap-2">
                {rep[ph].map((u) => (
                  // eslint-disable-next-line @next/next/no-img-element -- رابط موقّع مؤقت من الـ API، لا يُحسَّن
                  <a key={u} href={u} target="_blank" rel="noopener noreferrer"><img src={u} alt={s[ph]} className="h-24 rounded-sm object-cover" /></a>
                ))}
              </div>
            </div>
          ))}
          {rep.report.pdf_url && <a href={rep.report.pdf_url} target="_blank" rel="noopener noreferrer" className="mt-3 inline-block font-semibold underline">{s.reportPdf}</a>}
        </section>
      ))}
      {c?.handover && (
        <p className="text-sm">{s.handedOver}{c.handover.handed_over_at && ` · ${time.format(new Date(c.handover.handed_over_at))}`} · {c.handover.receiver_name}
          {c.handover.receipt_url && <> · <a href={c.handover.receipt_url} target="_blank" rel="noopener noreferrer" className="underline">{s.handoverReceipt}</a></>}</p>
      )}
      {c?.invoice && (
        <p className="text-sm">{s.invoice}: <Link href={`/${lang}/account/invoices`} className="num underline" dir="ltr">{c.invoice.number}</Link> · <span className="num">{formatMoney(c.invoice.total, lang)}</span></p>
      )}
      {c?.review && <p className="text-sm">{s.yourRating}: <span className="text-sun">{"★".repeat(c.review.rating)}</span>{c.review.comment && ` · ${c.review.comment}`}</p>}
      {c?.can_review && <ReviewForm lang={lang} t={s} id={r.id} until={c.review_until} />}

      {r.decline_reason && <Notice tone="danger">{s.declined}: {r.decline_reason}</Notice>}
      {r.cancel_reason && r.status === "cancelled" && <Notice tone="info">{s.cancelReason}: {r.cancel_reason}</Notice>}
      {r.can_cancel && (
        <ActionButton action={cancelServiceRequest} fields={{ lang, id: r.id }} label={s.cancel} look="danger"
          confirm={{ question: r.intake ? s.cancelQReceived : s.cancelQ, yes: s.yesCancel, no: s.no }} />
      )}
    </>
  );
}
