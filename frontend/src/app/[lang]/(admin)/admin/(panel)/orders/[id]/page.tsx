import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone } from "@/lib/format";
import { ORDER_COLOR, type OrderDetail } from "@/lib/order-types";
import { DeliverForm, PhoneForm, ReasonStep, Step } from "../OrderForms";

// A-07 تفاصيل الطلب: قائمة التجهيز للطباعة، والانتقالات، والتسليم مع الدفعة في خطوة واحدة (BR-18)
type Props = PageProps<"/[lang]/admin/orders/[id]">;

export default function AdminOrderPage({ params }: Props) {
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
  const [res, me] = await Promise.all([authed<OrderDetail>("admin", lang, `/admin/orders/${id}`), currentUser("admin", lang)]);
  if (!res.ok) notFound();
  const o = res.data;
  const a = t.admin.orders;
  const d = t.orders;
  const can = me.permissions.includes("orders.deliver");
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const balance = o.totals.balance?.amount ?? "0";

  return (
    <>
      <Link href={`/${lang}/admin/orders`} className="text-sm font-semibold text-ink-muted print:hidden">← {a.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{o.number}</h1>
          <p className="mt-1 text-ink-muted">
            {d.fulfillment[o.fulfillment]} · {o.branch?.name} · <span className="font-semibold" style={{ color: ORDER_COLOR[o.status] }}>{d.statuses[o.status]}</span>
          </p>
        </div>
        <div className="text-end text-sm">
          <p className="font-semibold">{o.customer?.organization ?? o.customer?.name}</p>
          {o.customer?.organization && <p>{o.customer.name}</p>}
          {o.customer?.phone && <a href={`tel:${o.customer.phone}`} className="num" dir="ltr">{formatPhone(o.customer.phone)}</a>}
        </div>
      </div>

      {o.needs_phone_confirmation && <p role="status" className="rounded-sm bg-sand-200 p-3 font-semibold text-[var(--status-waiting)]">{a.needsPhone}</p>}
      {o.cancel_reason && <p className="rounded-sm bg-sand-200 p-3">{d.cancelReason}: {o.cancel_reason}</p>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-lg">{a.picklist}</h2>
          </div>
          <table className="mt-3 w-full text-sm">
            <thead className="text-ink-muted">
              <tr><th className="py-2 text-start">{d.item}</th><th className="py-2 text-end">{d.qty}</th><th className="py-2 text-end">{d.lineTotal}</th></tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {o.items.map((i) => (
                <tr key={i.id}>
                  <td className="py-2"><span className="num font-semibold" dir="ltr">{i.part_number}</span> · {i.name}</td>
                  <td className="num py-2 text-end text-base font-bold">{Number(i.quantity)} <span className="text-xs font-normal text-ink-muted">{(t.admin.parts.units as Record<string, string>)[i.unit] ?? i.unit}</span></td>
                  <td className="num py-2 text-end">{i.line_subtotal && formatMoney(i.line_subtotal, lang)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <dl className="ms-auto mt-4 max-w-xs space-y-1 text-sm">
            {o.fulfillment === "delivery" && <div className="flex justify-between"><dt>{t.checkout.fee}</dt><dd className="num">{o.totals.delivery_fee && formatMoney(o.totals.delivery_fee, lang)}</dd></div>}
            <div className="flex justify-between"><dt>{t.cart.vat}</dt><dd className="num">{o.totals.vat_total && formatMoney(o.totals.vat_total, lang)}</dd></div>
            <div className="flex justify-between border-t border-sand-300 pt-1 font-bold"><dt>{t.cart.total}</dt><dd className="num">{o.totals.total && formatMoney(o.totals.total, lang)}</dd></div>
            <div className="flex justify-between"><dt>{d.paid}</dt><dd className="num">{o.totals.paid && formatMoney(o.totals.paid, lang)}</dd></div>
          </dl>
          {o.fulfillment === "delivery" && o.delivery_address && (
            <div className="mt-4 rounded-sm bg-sand-100 p-3 text-sm">
              <p className="font-semibold">{d.address}</p>
              <p>{o.delivery_address.text}</p>
            </div>
          )}
          {o.notes && <p className="mt-3 text-sm"><b>{a.notes}:</b> {o.notes}</p>}
          {o.invoice?.pdf_url && (
            <p className="mt-3 text-sm">{d.invoice} <span className="num" dir="ltr">{o.invoice.number}</span> · <a href={o.invoice.pdf_url} target="_blank" rel="noopener noreferrer" className="underline">PDF</a></p>
          )}
          {o.payments.length > 0 && (
            <ul className="mt-3 space-y-1 text-sm text-ink-muted">
              {o.payments.map((p) => (
                <li key={p.id}>{d.methods[p.method]} · <span className="num">{p.amount && formatMoney(p.amount, lang)}</span>{p.reference && <> · <span className="num" dir="ltr">{p.reference}</span></>} · {p.by}</li>
              ))}
            </ul>
          )}
        </section>

        <aside className="space-y-4 print:hidden">
          {can && o.status === "confirmed" && (
            <section className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4">
              {o.phone_confirmation && !o.phone_confirmation.confirmed_at && <PhoneForm lang={lang} t={a} id={o.id} />}
              <Step lang={lang} id={o.id} op="process" label={a.process} look="primary" />
            </section>
          )}
          {can && o.status === "processing" && (
            <section className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4">
              {o.fulfillment === "pickup"
                ? <Step lang={lang} id={o.id} op="ready" label={a.ready} look="primary" />
                : <Step lang={lang} id={o.id} op="dispatch" label={a.dispatch} look="primary" />}
            </section>
          )}
          {can && (o.status === "ready_for_pickup" || o.status === "out_for_delivery") && (
            <DeliverForm lang={lang} t={a} methods={d.methods} id={o.id} pickup={o.status === "ready_for_pickup"} balance={balance} />
          )}
          {can && o.status === "out_for_delivery" && <ReasonStep lang={lang} t={a} id={o.id} op="delivery-failed" label={a.failed} />}
          {can && (o.status === "confirmed" || o.status === "processing") && <ReasonStep lang={lang} t={a} id={o.id} op="cancel" label={a.cancel} danger />}

          <section className="rounded-md border border-sand-300 bg-sand-0 p-4">
            <h2 className="text-base">{d.timeline}</h2>
            <ol className="mt-3 space-y-3 border-s-2 border-sand-300 ps-4 text-sm">
              {o.timeline.map((e, n) => (
                <li key={n}>
                  <p className="font-semibold">{d.events[e.status as keyof typeof d.events] ?? e.status}</p>
                  <p className="text-xs text-ink-muted">{e.at && time.format(new Date(e.at))}{e.by && ` · ${e.by}`}</p>
                  {e.note && <p className="text-xs">{e.note}</p>}
                </li>
              ))}
            </ol>
          </section>
        </aside>
      </div>
    </>
  );
}
