import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { INVOICE_COLOR, type InvoiceRow } from "@/lib/order-types";

// C-18 الفواتير (FR-PAY-03، US-22): فواتير الحساب المحدد، والمتأخرة معلّمة (BR-15)، والإشعارات الدائنة معها
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/invoices">;
const FILTERS = ["all", "unpaid", "paid", "void"] as const;
type Filter = (typeof FILTERS)[number];

export default async function InvoicesPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.invoices.title}</h1>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const filter: Filter = FILTERS.includes(sp.status as Filter) ? (sp.status as Filter) : "all";
  const account = await selectedAccount(await currentUser("customer", lang));
  const res = await authed<InvoiceRow[]>("customer", lang, `/me/invoices?${filter === "all" ? "" : `status=${filter}&`}${account.query}`);
  const rows = res.ok ? res.data : [];
  const v = t.invoices;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "UTC" });

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={v.title}>
        {FILTERS.map((x) => (
          <Link key={x} href={x === "all" ? "?" : `?status=${x}`} aria-current={x === filter ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === filter ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {v.filters[x]}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{v.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="bg-sand-200">
              <tr>
                <th className="px-3 py-2 text-start">{v.number}</th>
                <th className="px-3 py-2 text-start">{v.date}</th>
                <th className="px-3 py-2 text-start">{v.source}</th>
                <th className="px-3 py-2 text-end">{v.total}</th>
                <th className="px-3 py-2 text-end">{v.paid}</th>
                <th className="px-3 py-2 text-start">{v.due}</th>
                <th className="px-3 py-2 text-start">{v.status}</th>
                <th className="px-3 py-2 text-start"><span className="sr-only">PDF</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((i) => (
                <tr key={i.id} className="align-top">
                  <td className="px-3 py-2">
                    <span className="num font-semibold" dir="ltr">{i.number}</span>
                    {i.credit_notes.map((c) => (
                      <span key={c.id} className="mt-1 block text-xs text-ink-muted">
                        {v.creditNote} <span className="num" dir="ltr">{c.number}</span>
                        {c.pdf_url && <> · <a href={c.pdf_url} target="_blank" rel="noopener noreferrer" className="underline">PDF</a></>}
                      </span>
                    ))}
                  </td>
                  <td className="px-3 py-2">{i.issue_date && date.format(new Date(i.issue_date))}</td>
                  <td className="px-3 py-2">
                    {v.sources[i.source.type]}
                    {i.source.number && <span className="num block text-xs text-ink-muted" dir="ltr">{i.source.number}</span>}
                    {i.account && <span className="block text-xs text-ink-muted">{i.account.name}</span>}
                  </td>
                  <td className="num px-3 py-2 text-end">{i.total && formatMoney(i.total, lang)}</td>
                  <td className="num px-3 py-2 text-end">{i.paid && formatMoney(i.paid, lang)}</td>
                  <td className="px-3 py-2">{i.due_date && date.format(new Date(i.due_date))}</td>
                  <td className="px-3 py-2">
                    <span className="font-semibold" style={{ color: INVOICE_COLOR[i.status] }}>{v.statuses[i.status]}</span>
                    {i.overdue && <span className="ms-1 rounded-sm bg-danger px-1.5 py-0.5 text-xs font-bold text-white">{v.overdue}</span>}
                  </td>
                  <td className="px-3 py-2">{i.pdf_url && <a href={i.pdf_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-sun-text underline">PDF</a>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
