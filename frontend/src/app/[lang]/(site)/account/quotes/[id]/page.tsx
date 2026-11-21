import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Conversation, type ThreadMessage } from "@/components/Conversation";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { fill, getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { sendMessage, uploadPrivate } from "../actions";
import { specLines } from "@/lib/rfq";
import { daysLeft, RFQ_COLOR, type Quotation, type RfqDetail } from "../shared";
import { QuoteActions, RfqAction } from "./QuoteActions";

// C-09 تفاصيل الطلب والعرض (FR-RFQ-03، FR-RFQ-04، FR-MSG-01، FR-MSG-02، US-13، US-21)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/quotes/[id]">;

export default function RfqPage({ params, searchParams }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <Detail params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Detail({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang, id }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const [res, thread] = await Promise.all([
    authed<RfqDetail>("customer", lang, `/me/rfqs/${id}`),
    authed<ThreadMessage[]>("customer", lang, `/me/rfqs/${id}/messages`),
  ]);
  if (!res.ok) notFound();
  const r = res.data;
  const q = t.quotes;
  const d = q.detail;
  const current = r.quotations[0];
  const older = r.quotations.slice(1);
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const wa = (r.type === "parts" ? r.branch?.whatsapp_parts : r.branch?.whatsapp_sales)?.replace(/\D/g, "");

  return (
    <>
      <Link href={`/${lang}/account/quotes`} className="text-sm font-semibold text-ink-muted">← {q.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{r.number}</h1>
          <p className="mt-1 text-ink-muted">{q.types[r.type]} · {date.format(new Date(r.created_at))} · <span className="font-semibold" style={{ color: RFQ_COLOR[r.status] }}>{q.rfqStatuses[r.status]}</span></p>
        </div>
        {wa && (
          <a href={`https://wa.me/${wa}?text=${encodeURIComponent(fill(d.waText, { number: r.number }))}`} target="_blank" rel="noopener noreferrer"
            className="rounded-sm bg-success px-4 py-2 font-semibold text-white">{d.whatsapp}</a>
        )}
      </div>
      {sp.sent && <Notice tone="success">{q.form.sent}</Notice>}

      {current ? <QuoteView lang={lang} r={r} quote={current} t={t} /> : (
        r.status !== "cancelled" && r.status !== "lost" && <Notice>{d.noQuote}</Notice>
      )}

      {r.status === "lost" && r.lost_reason && r.lost_reason !== "expired" && <Notice tone="danger">{d.lostReason}: {r.lost_reason.replace(/^rejected:?\s*/, "")}</Notice>}
      {r.can_renew && <RfqAction lang={lang} rfqId={r.id} op="renew" label={d.renew} done={d.renewed} />}

      <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="mb-3 text-xl">{d.request}</h2>
        <ol className="space-y-2">
          {r.items.map((i) => (
            <li key={i.id} className="flex flex-wrap items-start justify-between gap-2 border-b border-sand-200 pb-2 last:border-0">
              <div>
                <p className="font-semibold">
                  {i.listing ? <Link href={`/${lang}/equipment/${i.listing.slug}`} className="underline-offset-4 hover:underline">{i.title}</Link>
                    : i.part ? <Link href={`/${lang}/parts/${i.part.brand_slug}/${encodeURIComponent(i.part.part_number)}`} className="underline-offset-4 hover:underline">{i.title}</Link>
                    : i.title}
                </p>
                {i.specs && <p className="text-xs text-ink-muted">{specLines(i.specs, q.form)}</p>}
                {i.attachments.map((a) => a.url && <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="me-2 text-sm underline">📷 {a.name}</a>)}
              </div>
              <span className="num font-semibold">× {Number(i.quantity)}</span>
            </li>
          ))}
        </ol>
        {r.notes && <p className="mt-3 whitespace-pre-line text-sm"><span className="font-semibold">{d.notes}:</span> {r.notes}</p>}
        {r.can_cancel && <div className="mt-4"><RfqAction lang={lang} rfqId={r.id} op="cancel" label={d.cancel} confirmLabel={d.confirmCancel} reasonLabel={d.cancelReason} /></div>}
      </section>

      {older.length > 0 && (
        <details className="rounded-md border border-sand-300 bg-sand-0 p-4">
          <summary className="cursor-pointer font-semibold">{d.versions} ({older.length})</summary>
          <ul className="mt-3 space-y-2 text-sm">
            {older.map((o) => (
              <li key={o.id} className="flex flex-wrap justify-between gap-2">
                <span><span className="num" dir="ltr">{o.number}</span> · {q.quoteStatuses[o.status]}{o.response_note ? ` · ${d.yourNote}: ${o.response_note}` : ""}</span>
                <span className="flex gap-3"><span className="num">{formatMoney(o.total, lang)}</span>
                  <a href={`/${lang}/account/quotes/${r.id}/pdf?q=${o.id}`} className="underline">PDF</a></span>
              </li>
            ))}
          </ul>
        </details>
      )}

      <section id="messages" className="rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="mb-3 text-xl">{d.messages}</h2>
        <Conversation lang={lang} initial={thread.ok ? thread.data : []} upload={uploadPrivate} send={sendMessage.bind(null, lang, r.id)}
          labels={{ placeholder: d.messagePlaceholder, send: d.send, attach: d.attach, empty: d.noMessages, team: d.team }} />
      </section>
    </>
  );
}

function QuoteView({ lang, r, quote, t }: { lang: "ar" | "en"; r: RfqDetail; quote: Quotation; t: Awaited<ReturnType<typeof getAppDictionary>> }) {
  const q = t.quotes;
  const d = q.detail;
  const left = quote.status === "sent" && quote.valid_until ? daysLeft(quote.valid_until) : null;
  const desc = (l: Quotation["items"][number]) => (lang === "ar" ? l.description_ar : l.description_en);
  const terms = (["delivery_terms", "warranty_terms", "payment_terms", "estimated_duration"] as const).filter((k) => quote[k]);

  return (
    <section className="space-y-4 rounded-md border-2 border-sun bg-sand-0 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl">{d.quote} <span className="num" dir="ltr">{quote.number}</span></h2>
          <p className="text-sm text-ink-muted">{q.quoteStatuses[quote.status]}{quote.valid_until && <> · {q.validUntil} <span className="num">{quote.valid_until}</span></>}</p>
        </div>
        <div className="text-end">
          <p className="num text-2xl font-extrabold">{formatMoney(quote.total, lang)}</p>
          {left !== null && <p className="text-sm font-semibold text-[var(--status-waiting)]">{left <= 0 ? q.lastDay : fill(q.daysLeft, { n: String(left) })}</p>}
        </div>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-sm">
          <thead className="bg-sand-200">
            <tr>{[d.description, d.qty, d.unitPrice, d.discount, d.vat, d.lineTotal].map((h) => <th key={h} scope="col" className="px-3 py-2 text-start">{h}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-sand-300">
            {quote.items.map((l) => (
              <tr key={l.id}>
                <td className="px-3 py-2">{desc(l)}{l.warranty_months ? <span className="block text-xs text-ink-muted">{t.quotes.detail.terms.warranty_terms}: {l.warranty_months}</span> : null}</td>
                <td className="num px-3 py-2">{Number(l.quantity)} {l.unit}</td>
                <td className="num px-3 py-2">{formatMoney({ amount: l.unit_price, currency: "OMR" }, lang)}</td>
                <td className="num px-3 py-2">{Number(l.discount_amount) ? formatMoney({ amount: l.discount_amount, currency: "OMR" }, lang) : "—"}</td>
                <td className="num px-3 py-2">{Number(l.vat_rate)}%</td>
                <td className="num px-3 py-2 font-semibold">{formatMoney({ amount: l.line_total, currency: "OMR" }, lang)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <dl className="ms-auto w-full max-w-xs space-y-1 text-sm">
        <div className="flex justify-between"><dt>{d.subtotal}</dt><dd className="num">{formatMoney(quote.subtotal, lang)}</dd></div>
        {Number(quote.discount_total.amount) > 0 && <div className="flex justify-between"><dt>{d.discount}</dt><dd className="num">− {formatMoney(quote.discount_total, lang)}</dd></div>}
        <div className="flex justify-between"><dt>{d.vatTotal}</dt><dd className="num">{formatMoney(quote.vat_total, lang)}</dd></div>
        <div className="flex justify-between border-t border-ink pt-1 text-base font-bold"><dt>{d.grandTotal}</dt><dd className="num">{formatMoney(quote.total, lang)}</dd></div>
      </dl>
      <p className="text-xs text-ink-muted">{d.pricesNote}</p>
      {terms.length > 0 && (
        <dl className="grid gap-2 text-sm sm:grid-cols-2">
          {terms.map((k) => <div key={k}><dt className="font-semibold">{d.terms[k]}</dt><dd className="whitespace-pre-line text-ink-muted">{quote[k]}</dd></div>)}
        </dl>
      )}

      <div className="flex flex-wrap items-center gap-3 border-t border-sand-300 pt-4">
        <a href={`/${lang}/account/quotes/${r.id}/pdf?q=${quote.id}`} className="rounded-sm border border-ink px-4 py-2 font-semibold">{d.pdf} ↓</a>
        {quote.status === "accepted" && <Notice tone="success">{d.accepted}</Notice>}
        {quote.status === "expired" && <Notice tone="danger">{d.expired}</Notice>}
      </div>
      {quote.acceptable && (
        <QuoteActions lang={lang} t={d} rfqId={r.id} quotationId={quote.id} canDecide={r.can_decide} approvalRequested={Boolean(quote.approval_requested_at)} />
      )}
    </section>
  );
}
