import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { INVOICE_COLOR, type InvoiceDetail } from "@/lib/order-types";
import { IssueForm, PaymentForm, RefundForm, VoidForm } from "../../FinanceForms";

// A-13 تفاصيل الفاتورة: إصدار المسودة، والدفعات اليدوية، والاسترداد، والإلغاء بإشعار دائن (SM-6)
type Props = PageProps<"/[lang]/admin/finance/invoices/[id]">;

export default function InvoicePage({ params }: Props) {
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
  const [res, me] = await Promise.all([authed<InvoiceDetail>("admin", lang, `/admin/invoices/${id}`), currentUser("admin", lang)]);
  if (!res.ok) notFound();
  const i = res.data;
  const f = t.admin.finance;
  const v = t.invoices;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "UTC" });
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });
  const unpaid = i.status === "issued" || i.status === "partially_paid";
  const canRefund = me.permissions.includes("refunds.manage");

  return (
    <>
      <Link href={`/${lang}/admin/finance`} className="text-sm font-semibold text-ink-muted">← {f.title}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="num text-3xl" dir="ltr">{i.number ?? f.draftLabel}</h1>
          <p className="mt-1 text-ink-muted">
            {v.sources[i.source.type]} <span className="num" dir="ltr">{i.source.number}</span> ·{" "}
            <span className="font-semibold" style={{ color: INVOICE_COLOR[i.status] }}>{v.statuses[i.status]}</span>
            {i.overdue && <span className="ms-1 rounded-sm bg-danger px-1.5 py-0.5 text-xs font-bold text-white">{v.overdue}</span>}
          </p>
        </div>
        {i.pdf_url && <a href={i.pdf_url} target="_blank" rel="noopener noreferrer" className="rounded-sm border border-ink px-4 py-2 font-semibold">PDF</a>}
      </div>
      {i.void_reason && <p className="rounded-sm bg-sand-200 p-3">{f.voidReason}: {i.void_reason}</p>}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-6">
          <section className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-5 text-sm sm:grid-cols-2">
            <div>
              <h2 className="text-base">{f.buyer}</h2>
              <p className="mt-1 font-semibold">{i.buyer.name}</p>
              {i.buyer.cr_number && <p>CR <span className="num" dir="ltr">{i.buyer.cr_number}</span></p>}
              {i.buyer.vat_number && <p>VAT <span className="num" dir="ltr">{i.buyer.vat_number}</span></p>}
              {i.buyer.phone && <p className="num" dir="ltr">{i.buyer.phone}</p>}
            </div>
            <div>
              <h2 className="text-base">{f.dates}</h2>
              <p className="mt-1">{v.date}: {i.issue_date ? date.format(new Date(i.issue_date)) : "—"}</p>
              <p>{v.due}: {i.due_date ? date.format(new Date(i.due_date)) : "—"}</p>
            </div>
          </section>

          <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
            <h2 className="text-lg">{f.lines}</h2>
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead className="text-ink-muted">
                  <tr><th className="py-2 text-start">#</th><th className="py-2 text-start">{t.orders.item}</th><th className="py-2 text-end">{t.orders.qty}</th>
                    <th className="py-2 text-end">{t.orders.price}</th><th className="py-2 text-end">{t.cart.vat}</th><th className="py-2 text-end">{t.orders.lineTotal}</th></tr>
                </thead>
                <tbody className="divide-y divide-sand-300">
                  {i.items.map((l) => (
                    <tr key={l.line_no}>
                      <td className="num py-2">{l.line_no}</td>
                      <td className="py-2">{l.description}{l.warranty_until && <span className="block text-xs text-ink-muted">{f.warrantyUntil} {date.format(new Date(l.warranty_until))}</span>}</td>
                      <td className="num py-2 text-end">{Number(l.quantity)}</td>
                      <td className="num py-2 text-end">{l.unit_price && formatMoney(l.unit_price, lang)}</td>
                      <td className="num py-2 text-end">{l.line_vat && formatMoney(l.line_vat, lang)}</td>
                      <td className="num py-2 text-end">{l.line_total && formatMoney(l.line_total, lang)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <dl className="ms-auto mt-4 max-w-xs space-y-1 text-sm">
              <div className="flex justify-between"><dt>{t.cart.subtotal}</dt><dd className="num">{i.subtotal && formatMoney(i.subtotal, lang)}</dd></div>
              {i.discount_total && Number(i.discount_total.amount) > 0 && <div className="flex justify-between"><dt>{f.discount}</dt><dd className="num">− {formatMoney(i.discount_total, lang)}</dd></div>}
              <div className="flex justify-between"><dt>{t.cart.vat}</dt><dd className="num">{i.vat_total && formatMoney(i.vat_total, lang)}</dd></div>
              <div className="flex justify-between border-t border-sand-300 pt-1 font-bold"><dt>{t.cart.total}</dt><dd className="num">{i.total && formatMoney(i.total, lang)}</dd></div>
              <div className="flex justify-between"><dt>{v.paid}</dt><dd className="num">{i.paid && formatMoney(i.paid, lang)}</dd></div>
              <div className="flex justify-between font-semibold"><dt>{f.balance}</dt><dd className="num">{i.balance && formatMoney(i.balance, lang)}</dd></div>
            </dl>
          </section>

          <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
            <h2 className="text-lg">{f.payments}</h2>
            {i.payments.length === 0 ? <p className="mt-2 text-sm text-ink-muted">{f.noRows}</p> : (
              <ul className="mt-2 space-y-3 text-sm">
                {i.payments.map((p) => (
                  <li key={p.id} className="rounded-sm border border-sand-300 p-3">
                    <p className="flex flex-wrap justify-between gap-2">
                      <span>{t.orders.methods[p.method]} · {p.received_at && time.format(new Date(p.received_at))}{p.reference && <> · <span className="num" dir="ltr">{p.reference}</span></>}{p.by && ` · ${p.by}`}</span>
                      <span className="num font-bold">{p.amount && formatMoney(p.amount, lang)}</span>
                    </p>
                    {p.refunds.map((r) => (
                      <p key={r.id} className="mt-1 text-xs text-danger">{f.refunded} <span className="num">{r.amount && formatMoney(r.amount, lang)}</span> · {r.reason}</p>
                    ))}
                    {canRefund && (p.status === "succeeded" || p.status === "partially_refunded") && i.status !== "void" && (
                      <RefundForm lang={lang} t={f} methods={t.orders.methods} invoice={i.id} payment={p.id}
                        max={String((Number(p.amount?.amount ?? 0) - Number(p.refunded?.amount ?? 0)).toFixed(3))} />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </section>

          {i.credit_notes.length > 0 && (
            <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
              <h2 className="text-lg">{f.creditNotes}</h2>
              <ul className="mt-2 space-y-1">
                {i.credit_notes.map((c) => (
                  <li key={c.id}><span className="num" dir="ltr">{c.number}</span> · <span className="num">{c.total && formatMoney(c.total, lang)}</span> · {c.reason}
                    {c.pdf_url && <> · <a href={c.pdf_url} target="_blank" rel="noopener noreferrer" className="underline">PDF</a></>}</li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <aside className="space-y-4">
          {i.status === "draft" && <IssueForm lang={lang} t={f} id={i.id} />}
          {unpaid && <PaymentForm key={i.balance?.amount} lang={lang} t={f} methods={t.orders.methods} id={i.id} balance={i.balance?.amount ?? "0"} />}
          {i.status === "issued" && i.payments.length === 0 && <VoidForm lang={lang} t={f} id={i.id} />}
        </aside>
      </div>
    </>
  );
}
