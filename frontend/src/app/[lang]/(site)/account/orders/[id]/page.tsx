import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { fill, getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { ORDER_COLOR, type OrderDetail } from "@/lib/order-types";
import { CancelOrder, ReturnForm } from "./OrderForms";

// C-14 تفاصيل طلب القطع (FR-ORD-03، FR-ORD-05، US-15، US-16): الخط الزمني، ورمز الاستلام ومهلته،
// والمبلغ المستحق، والفاتورة، والإلغاء قبل التجهيز (BR-13)، والإرجاع خلال مدته (BR-12)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/orders/[id]">;

export default function OrderPage({ params, searchParams }: Props) {
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
  const res = await authed<OrderDetail>("customer", lang, `/me/orders/${id}`);
  if (!res.ok) notFound();
  const o = res.data;
  const d = t.orders;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const wa = o.branch_detail?.whatsapp?.replace(/\D/g, "");
  const balanceDue = o.totals.balance && Number(o.totals.balance.amount) > 0;

  return (
    <>
      <Link href={`/${lang}/account/orders`} className="text-sm font-semibold text-ink-muted">← {d.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{o.number}</h1>
          <p className="mt-1 text-ink-muted">
            {o.fulfillment === "pickup" ? d.fulfillment.pickup : d.fulfillment.delivery}
            {o.created_at && <> · {date.format(new Date(o.created_at))}</>}
            {" · "}<span className="font-semibold" style={{ color: ORDER_COLOR[o.status] }}>{d.statuses[o.status]}</span>
            {o.from_quote && <> · {d.fromQuote}</>}
          </p>
          {o.account && <p className="text-sm text-ink-muted">{o.account.name}</p>}
        </div>
        {wa && (
          <a href={`https://wa.me/${wa}?text=${encodeURIComponent(fill(d.waText, { number: o.number }))}`} target="_blank" rel="noopener noreferrer"
            className="rounded-sm bg-success px-4 py-2 font-semibold text-white">{d.contact}</a>
        )}
      </div>

      {sp.placed && <Notice tone="success">{d.placed}</Notice>}

      {o.status === "ready_for_pickup" && o.pickup_code && (
        <section className="rounded-md border-2 border-sun bg-sand-0 p-5 text-center">
          <h2 className="text-lg">{d.pickupCode}</h2>
          <p className="num mt-2 text-5xl font-extrabold tracking-[0.3em]" dir="ltr">{o.pickup_code}</p>
          <p className="mt-2 text-sm text-ink-muted">{d.pickupCodeHint}</p>
          {o.pickup_deadline_at && <p className="mt-1 font-semibold">{fill(d.pickupUntil, { date: time.format(new Date(o.pickup_deadline_at)) })}</p>}
          {o.branch_detail && <p className="mt-1 text-sm">{o.branch_detail.name}{o.branch_detail.address && <> · {o.branch_detail.address}</>}</p>}
        </section>
      )}

      {balanceDue && o.status !== "cancelled" && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-4">
          <p className="font-bold">{d.due}: <span className="num">{o.totals.balance && formatMoney(o.totals.balance, lang)}</span></p>
          <p className="mt-1 text-sm text-ink-muted">{d.accepted}</p>
        </section>
      )}
      {o.status === "cancelled" && o.cancel_reason && <Notice tone="danger">{d.cancelReason}: {o.cancel_reason}</Notice>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <h2 className="text-lg">{d.itemsTitle}</h2>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[480px] text-sm">
              <thead className="text-ink-muted">
                <tr><th className="py-2 text-start">{d.item}</th><th className="py-2 text-end">{d.qty}</th><th className="py-2 text-end">{d.price}</th><th className="py-2 text-end">{d.lineTotal}</th></tr>
              </thead>
              <tbody className="divide-y divide-sand-300">
                {o.items.map((i) => (
                  <tr key={i.id}>
                    <td className="py-2">
                      <span className="num font-semibold" dir="ltr">{i.part_number}</span> · {i.name}
                      <span className="block text-xs text-ink-muted">
                        {i.is_returnable ? null : d.notReturnable}{i.warranty_months ? ` ${fill(d.warranty, { n: String(i.warranty_months) })}` : ""}
                      </span>
                    </td>
                    <td className="num py-2 text-end">{Number(i.quantity)}</td>
                    <td className="num py-2 text-end">{i.unit_price && formatMoney(i.unit_price, lang)}</td>
                    <td className="num py-2 text-end">{i.line_subtotal && formatMoney(i.line_subtotal, lang)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <dl className="ms-auto mt-4 max-w-xs space-y-1 text-sm">
            <div className="flex justify-between"><dt>{t.cart.subtotal}</dt><dd className="num">{o.totals.subtotal && formatMoney(o.totals.subtotal, lang)}</dd></div>
            {o.fulfillment === "delivery" && <div className="flex justify-between"><dt>{t.checkout.fee}</dt><dd className="num">{o.totals.delivery_fee && formatMoney(o.totals.delivery_fee, lang)}</dd></div>}
            <div className="flex justify-between"><dt>{t.cart.vat}</dt><dd className="num">{o.totals.vat_total && formatMoney(o.totals.vat_total, lang)}</dd></div>
            <div className="flex justify-between border-t border-sand-300 pt-1 font-bold"><dt>{t.cart.total}</dt><dd className="num">{o.totals.total && formatMoney(o.totals.total, lang)}</dd></div>
            <div className="flex justify-between text-ink-muted"><dt>{d.paid}</dt><dd className="num">{o.totals.paid && formatMoney(o.totals.paid, lang)}</dd></div>
          </dl>
          {o.invoice?.pdf_url && (
            <p className="mt-4 text-sm">
              {d.invoice} <span className="num" dir="ltr">{o.invoice.number}</span> ·{" "}
              <a href={o.invoice.pdf_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-sun-text underline">{d.downloadInvoice}</a>
            </p>
          )}
        </section>

        <aside className="space-y-4">
          <section className="rounded-md border border-sand-300 bg-sand-0 p-4">
            <h2 className="text-base">{d.timeline}</h2>
            <ol className="mt-3 space-y-3 border-s-2 border-sand-300 ps-4 text-sm">
              {o.timeline.map((e, n) => (
                <li key={n}>
                  <p className="font-semibold">{d.events[e.status as keyof typeof d.events] ?? e.status}</p>
                  {e.at && <p className="text-xs text-ink-muted">{time.format(new Date(e.at))}</p>}
                  {e.note && <p className="text-xs">{e.note}</p>}
                </li>
              ))}
            </ol>
          </section>
          <section className="rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
            {o.fulfillment === "pickup" ? (
              <>
                <h2 className="text-base">{d.branch}</h2>
                <p className="mt-1">{o.branch_detail?.name}</p>
                {o.branch_detail?.address && <p className="text-ink-muted">{o.branch_detail.address}</p>}
              </>
            ) : (
              <>
                <h2 className="text-base">{d.address}</h2>
                <p className="mt-1">{o.delivery_address?.text}</p>
              </>
            )}
          </section>
          {o.can_cancel && <CancelOrder lang={lang} t={d} order={o.id} />}
          {o.status === "processing" && <p className="text-sm text-ink-muted">{d.cantCancel}</p>}
        </aside>
      </div>

      {o.can_return && <ReturnForm lang={lang} t={d} order={o.id} items={o.items} until={o.return_until ? date.format(new Date(o.return_until)) : ""} />}

      {o.returns.length > 0 && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <h2 className="text-lg">{d.returns}</h2>
          <ul className="mt-3 space-y-3 text-sm">
            {o.returns.map((r) => (
              <li key={r.id} className="rounded-sm border border-sand-300 p-3">
                <p className="font-semibold">{d.returnStatuses[r.status]}{r.created_at && <span className="font-normal text-ink-muted"> · {date.format(new Date(r.created_at))}</span>}</p>
                <p>{r.items.map((i) => `${i.part_number ?? ""} × ${Number(i.quantity)}`).join("، ")}</p>
                <p className="text-ink-muted">{r.reason}</p>
                {r.decision_note && <p className="mt-1">{r.decision_note}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
