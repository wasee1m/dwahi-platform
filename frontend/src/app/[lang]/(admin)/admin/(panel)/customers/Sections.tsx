import Link from "next/link";
import { intlLocale, type Locale } from "@/i18n/config";
import { fill, type AppDictionary } from "@/i18n/get-dictionary";
import type { Address, CustomerProfile, FleetItem } from "@/lib/account-types";
import { formatMoney, formatNumber, formatPhone, listSep } from "@/lib/format";
import { INVOICE_COLOR, ORDER_COLOR, type InvoiceRow, type OrderRow } from "@/lib/order-types";
import { SERVICE_COLOR, type ServiceRow } from "@/lib/service-types";
import { STATUS_COLOR, type AdminRfqRow } from "../rfqs/shared";
import { NoteForm } from "./CustomerForms";

// أقسام ملف 360° المشتركة بين العميل والشركة (A-12)
export const card = "rounded-md border border-sand-300 bg-sand-0 p-5";

export function Records({ lang, t, requests, conversations, fleet, addresses, canOpenRequests, orders, invoices, canOpenOrders, canOpenInvoices, services, canOpenServices }: {
  lang: Locale; t: AppDictionary; requests: AdminRfqRow[]; conversations: CustomerProfile["conversations"]; fleet: FleetItem[]; addresses: Address[]; canOpenRequests: boolean;
  orders: OrderRow[]; invoices: InvoiceRow[]; canOpenOrders: boolean; canOpenInvoices: boolean;
  services: ServiceRow[]; canOpenServices: boolean;
}) {
  const c = t.admin.customers;
  const r = t.admin.rfqs;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const none = <p className="text-sm text-ink-muted">{t.admin.common.none}</p>;
  // المحاسب يرى الملف ولا يفتح شاشة الطلبات (01 §3.2)
  const open = (id: string, number: string, hash = "") => canOpenRequests
    ? <Link href={`/${lang}/admin/rfqs/${id}${hash}`} className="num font-semibold underline" dir="ltr">{number}</Link>
    : <span className="num font-semibold" dir="ltr">{number}</span>;

  return (
    <>
      <section className={card}>
        <h2 className="mb-3 text-xl">{c.requests}</h2>
        {requests.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {requests.map((x) => (
              <li key={x.id} className="flex flex-wrap justify-between gap-3 py-2">
                <span>{open(x.id, x.number)} · {r.types[x.type]} · {date.format(new Date(x.created_at))}
                  <span className="block text-ink-muted">{x.summary.join(listSep(lang))}</span></span>
                <span className="text-end">
                  <span className="block font-semibold" style={{ color: STATUS_COLOR[x.status] }}>{r.statuses[x.status]}</span>
                  {x.quotation && <><span className="num" dir="ltr">{x.quotation.number}</span> · <span className="num">{formatMoney(x.quotation.total, lang)}</span></>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.service}</h2>
        {services.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {services.map((x) => (
              <li key={x.id} className="flex flex-wrap justify-between gap-3 py-2">
                <span>
                  {canOpenServices ? <Link href={`/${lang}/admin/service-requests/${x.id}`} className="num font-semibold underline" dir="ltr">{x.number}</Link> : <span className="num font-semibold" dir="ltr">{x.number}</span>}
                  {" · "}{x.equipment?.name ?? t.service.components[x.component_type as keyof typeof t.service.components]}
                </span>
                <span className="font-semibold" style={{ color: SERVICE_COLOR[x.status] }}>{t.admin.service.statuses[x.status]}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.orders}</h2>
        {orders.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {orders.map((x) => (
              <li key={x.id} className="flex flex-wrap justify-between gap-3 py-2">
                <span>
                  {canOpenOrders ? <Link href={`/${lang}/admin/orders/${x.id}`} className="num font-semibold underline" dir="ltr">{x.number}</Link> : <span className="num font-semibold" dir="ltr">{x.number}</span>}
                  {x.created_at && <> · {date.format(new Date(x.created_at))}</>}
                  <span className="block text-ink-muted">{x.summary.join(listSep(lang))}</span>
                </span>
                <span className="text-end">
                  <span className="block font-semibold" style={{ color: ORDER_COLOR[x.status] }}>{t.orders.statuses[x.status]}</span>
                  {x.total && <span className="num">{formatMoney(x.total, lang)}</span>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.invoices}</h2>
        {invoices.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {invoices.map((x) => (
              <li key={x.id} className="flex flex-wrap justify-between gap-3 py-2">
                <span>
                  {canOpenInvoices ? <Link href={`/${lang}/admin/finance/invoices/${x.id}`} className="num font-semibold underline" dir="ltr">{x.number}</Link> : <span className="num font-semibold" dir="ltr">{x.number}</span>}
                  {x.issue_date && <> · {date.format(new Date(x.issue_date))}</>}
                </span>
                <span className="text-end">
                  <span className="block font-semibold" style={{ color: INVOICE_COLOR[x.status] }}>{t.invoices.statuses[x.status]}</span>
                  {x.overdue && <span className="rounded-sm bg-danger px-1.5 text-xs font-bold text-white">{t.invoices.overdue}</span>}
                  {x.total && <span className="num ms-2">{formatMoney(x.total, lang)}</span>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.conversations}</h2>
        {conversations.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {conversations.map((x) => (
              <li key={x.rfq_id} className="flex justify-between gap-3 py-2">
                {open(x.rfq_id, x.number, "#messages")}
                <span className="text-ink-muted">{fill(c.messageCount, { n: String(x.messages) })}{x.last_message_at && ` · ${date.format(new Date(x.last_message_at))}`}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.fleet}</h2>
        {fleet.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {fleet.map((e) => (
              <li key={e.id} className="flex flex-wrap justify-between gap-3 py-2">
                <span className="font-semibold">{e.name}{e.serial_number && <span className="num ms-2 font-normal text-ink-muted" dir="ltr">{e.serial_number}</span>}</span>
                {e.hour_meter !== null && <span>{fill(t.fleet.hours, { n: formatNumber(e.hour_meter) })}</span>}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className={card}>
        <h2 className="mb-3 text-xl">{c.addresses}</h2>
        {addresses.length === 0 ? none : (
          <ul className="divide-y divide-sand-300 text-sm">
            {addresses.map((a) => (
              <li key={a.id} className="py-2">
                <span className="font-semibold">{a.label}</span> · {t.onboarding.governorates[a.governorate as keyof AppDictionary["onboarding"]["governorates"]]} · {a.wilayat}
                {a.description && <span className="block text-ink-muted">{a.description}</span>}
                {a.contact_phone && <span className="num block text-ink-muted" dir="ltr">{formatPhone(a.contact_phone)}</span>}
              </li>
            ))}
          </ul>
        )}
      </section>

    </>
  );
}

export function Notes({ lang, t, id, subject, notes }: { lang: Locale; t: AppDictionary; id: string; subject: "customer" | "company"; notes: CustomerProfile["notes"] }) {
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  return (
    <section className={`${card} space-y-3`}>
      <h2 className="text-xl">{t.admin.customers.notes}</h2>
      <NoteForm lang={lang} t={t.admin.customers} id={id} subject={subject} />
      <ol className="space-y-3 border-s-2 border-sand-300 ps-4 text-sm">
        {notes.map((n) => (
          <li key={n.id}>
            <p className="text-ink-muted"><span className="font-semibold text-ink">{n.author}</span> · {when.format(new Date(n.at))}</p>
            <p className="whitespace-pre-line">{n.body}</p>
          </li>
        ))}
      </ol>
    </section>
  );
}
