import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Money } from "@/lib/types";
import { INVOICE_COLOR, type CreditNoteRow, type InvoiceRow, type PaymentRow } from "@/lib/order-types";

// A-13 المالية (FR-PAY-02، FR-PAY-03، FR-PAY-06، US-38): الفواتير ومسوداتها، والدفعات، والتحصيل اليومي، والاستردادات، والإشعارات الدائنة
type Props = PageProps<"/[lang]/admin/finance">;
const TABS = ["invoices", "payments", "collection", "refunds", "credit"] as const;
type Tab = (typeof TABS)[number];
const FILTERS = ["all", "draft", "unpaid", "overdue", "paid", "void"] as const;

type Collection = {
  date: string; total: Money;
  branches: { branch: { id: string | null; name: string | null }; total: Money;
    by_method: { method: string; count: number; total: Money }[];
    by_staff: { staff: string | null; count: number; total: Money; by_method: { method: string; total: Money }[] }[];
    refunds: { method: string; count: number; total: Money }[] }[];
};
type Refunds = {
  pending_returns: { id: string; order: { id: string; number: string }; reason: string; received_at: string | null; amount: Money }[];
  refunds: { id: string; amount: Money; method: string; reason: string; reference: string | null; at: string | null; by: string | null;
    credit_note: { id: string; number: string } | null; invoice: { id: string; number: string | null } | null; order: { id: string; number: string } | null }[];
};

export default async function FinancePage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.finance.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Body lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Body({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("invoices.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "invoices";
  const f = t.admin.finance;
  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={f.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>{f.tabs[x]}</Link>
        ))}
      </nav>
      {tab === "invoices" && <Invoices lang={lang} t={t} sp={sp} />}
      {tab === "payments" && <Payments lang={lang} t={t} />}
      {tab === "collection" && <CollectionReport lang={lang} t={t} date={typeof sp.date === "string" ? sp.date : ""} />}
      {tab === "refunds" && <RefundList lang={lang} t={t} />}
      {tab === "credit" && <CreditNotes lang={lang} t={t} />}
    </>
  );
}

async function Invoices({ lang, t, sp }: { lang: Locale; t: AppDictionary; sp: Record<string, string | string[] | undefined> }) {
  const f = t.admin.finance;
  const filter = FILTERS.includes(sp.status as (typeof FILTERS)[number]) ? (sp.status as string) : "all";
  const q = typeof sp.q === "string" ? sp.q : "";
  const qs = new URLSearchParams({ ...(filter !== "all" ? { status: filter } : {}), ...(q ? { q } : {}) });
  const res = await authed<InvoiceRow[]>("admin", lang, `/admin/invoices?${qs.toString()}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Record<string, number>;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "UTC" });
  const v = t.invoices;
  return (
    <>
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-wrap gap-1">
          {FILTERS.map((x) => (
            <Link key={x} href={`?tab=invoices&status=${x}${q ? `&q=${encodeURIComponent(q)}` : ""}`} aria-current={x === filter ? "page" : undefined}
              className={`rounded-sm px-3 py-1.5 text-sm font-semibold ${x === filter ? "bg-night text-on-night" : "bg-sand-200"}`}>
              {f.filters[x]}{counts[x] ? <span className="num ms-1">({counts[x]})</span> : null}
            </Link>
          ))}
        </div>
        <form method="get" role="search" className="flex gap-2">
          <input type="hidden" name="tab" value="invoices" />
          <input type="hidden" name="status" value={filter} />
          <input name="q" defaultValue={q} placeholder={f.search} aria-label={f.search} className="rounded-sm border border-sand-300 bg-sand-0 px-3 py-1.5" />
          <button className="rounded-sm border border-ink px-3 py-1.5 text-sm font-semibold">{t.admin.orders.find}</button>
        </form>
      </div>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{f.noRows}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-sand-200">
              <tr>
                <th className="px-3 py-2 text-start">{v.number}</th><th className="px-3 py-2 text-start">{t.admin.orders.customer}</th>
                <th className="px-3 py-2 text-start">{v.source}</th><th className="px-3 py-2 text-start">{v.date}</th><th className="px-3 py-2 text-start">{v.due}</th>
                <th className="px-3 py-2 text-end">{v.total}</th><th className="px-3 py-2 text-end">{f.balance}</th><th className="px-3 py-2 text-start">{v.status}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((i) => (
                <tr key={i.id} className="hover:bg-sand-100">
                  <td className="px-3 py-2">
                    <Link href={`/${lang}/admin/finance/invoices/${i.id}`} className="num font-semibold text-sun-text hover:underline" dir="ltr">{i.number ?? f.draftLabel}</Link>
                  </td>
                  <td className="px-3 py-2">{i.account?.name ?? i.customer?.name}</td>
                  <td className="px-3 py-2">{v.sources[i.source.type]} <span className="num text-xs text-ink-muted" dir="ltr">{i.source.number}</span></td>
                  <td className="px-3 py-2">{i.issue_date && date.format(new Date(i.issue_date))}</td>
                  <td className="px-3 py-2">{i.due_date && date.format(new Date(i.due_date))}</td>
                  <td className="num px-3 py-2 text-end">{i.total && formatMoney(i.total, lang)}</td>
                  <td className="num px-3 py-2 text-end">{i.balance && formatMoney(i.balance, lang)}</td>
                  <td className="px-3 py-2">
                    <span className="font-semibold" style={{ color: INVOICE_COLOR[i.status] }}>{v.statuses[i.status]}</span>
                    {i.overdue && <span className="ms-1 rounded-sm bg-danger px-1.5 py-0.5 text-xs font-bold text-white">{v.overdue}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

async function Payments({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const res = await authed<PaymentRow[]>("admin", lang, "/admin/payments");
  const rows = res.ok ? res.data : [];
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });
  const f = t.admin.finance;
  if (rows.length === 0) return <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{f.noRows}</p>;
  return (
    <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-sand-200">
          <tr><th className="px-3 py-2 text-start">{f.when}</th><th className="px-3 py-2 text-start">{f.method}</th><th className="px-3 py-2 text-end">{f.amount}</th>
            <th className="px-3 py-2 text-start">{f.reference}</th><th className="px-3 py-2 text-start">{f.document}</th><th className="px-3 py-2 text-start">{f.by}</th></tr>
        </thead>
        <tbody className="divide-y divide-sand-300">
          {rows.map((p) => (
            <tr key={p.id}>
              <td className="px-3 py-2">{p.received_at && time.format(new Date(p.received_at))}</td>
              <td className="px-3 py-2">{t.orders.methods[p.method]}{p.status !== "succeeded" && <span className="block text-xs text-ink-muted">{f.paymentStatuses[p.status as keyof typeof f.paymentStatuses] ?? p.status}</span>}</td>
              <td className="num px-3 py-2 text-end">{p.amount && formatMoney(p.amount, lang)}</td>
              <td className="num px-3 py-2" dir="ltr">{p.reference}</td>
              <td className="px-3 py-2">
                {p.invoice && <Link href={`/${lang}/admin/finance/invoices/${p.invoice.id}`} className="num underline" dir="ltr">{p.invoice.number}</Link>}
                {p.order && <span className="num block text-xs text-ink-muted" dir="ltr">{p.order.number}</span>}
              </td>
              <td className="px-3 py-2">{p.by}{p.branch && <span className="block text-xs text-ink-muted">{p.branch}</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

async function CollectionReport({ lang, t, date }: { lang: Locale; t: AppDictionary; date: string }) {
  const res = await authed<Collection>("admin", lang, `/admin/finance/collection${/^\d{4}-\d{2}-\d{2}$/.test(date) ? `?date=${date}` : ""}`);
  if (!res.ok) return <Notice tone="danger">{res.error.message}</Notice>;
  const c = res.data;
  const f = t.admin.finance;
  const m = t.orders.methods as Record<string, string>;
  return (
    <div className="space-y-4">
      <form method="get" className="flex flex-wrap items-end gap-2">
        <input type="hidden" name="tab" value="collection" />
        <label className="text-sm"><span className="block font-semibold">{f.date}</span>
          <input type="date" name="date" defaultValue={c.date} className="rounded-sm border border-sand-300 bg-sand-0 px-3 py-1.5" />
        </label>
        <button className="rounded-sm border border-ink px-3 py-1.5 text-sm font-semibold">{f.show}</button>
      </form>
      <p className="text-lg font-bold">{f.dayTotal}: <span className="num">{formatMoney(c.total, lang)}</span></p>
      {c.branches.length === 0 && <p className="text-ink-muted">{f.noRows}</p>}
      {c.branches.map((b) => (
        <section key={b.branch.id ?? "none"} className="rounded-md border border-sand-300 bg-sand-0 p-4">
          <h2 className="text-lg">{b.branch.name ?? "—"} · <span className="num">{formatMoney(b.total, lang)}</span></h2>
          <div className="mt-3 grid gap-4 md:grid-cols-2">
            <div>
              <h3 className="text-sm font-bold">{f.byMethod}</h3>
              <ul className="mt-1 space-y-1 text-sm">
                {b.by_method.map((x) => <li key={x.method} className="flex justify-between"><span>{m[x.method] ?? x.method} ({x.count})</span><span className="num">{formatMoney(x.total, lang)}</span></li>)}
              </ul>
            </div>
            <div>
              <h3 className="text-sm font-bold">{f.byStaff}</h3>
              <ul className="mt-1 space-y-1 text-sm">
                {b.by_staff.map((x, n) => (
                  <li key={n}>
                    <span className="flex justify-between"><span>{x.staff ?? "—"} ({x.count})</span><span className="num">{formatMoney(x.total, lang)}</span></span>
                    <span className="block text-xs text-ink-muted">{x.by_method.map((y) => `${m[y.method] ?? y.method}: ${formatMoney(y.total, lang)}`).join(" · ")}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
          {b.refunds.length > 0 && (
            <p className="mt-3 text-sm text-danger">{f.refundsOfDay}: {b.refunds.map((r) => `${m[r.method] ?? r.method} ${formatMoney(r.total, lang)}`).join(" · ")}</p>
          )}
        </section>
      ))}
    </div>
  );
}

async function RefundList({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const res = await authed<Refunds>("admin", lang, "/admin/refunds");
  if (!res.ok) return <Notice tone="danger">{res.error.message}</Notice>;
  const f = t.admin.finance;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });
  return (
    <div className="space-y-6">
      <section>
        <h2 className="text-lg">{f.pendingReturns}</h2>
        {res.data.pending_returns.length === 0 ? <p className="mt-2 text-ink-muted">{f.noRows}</p> : (
          <ul className="mt-2 space-y-2 text-sm">
            {res.data.pending_returns.map((r) => (
              <li key={r.id} className="flex flex-wrap justify-between gap-2 rounded-sm border border-sand-300 bg-sand-0 p-3">
                <span><span className="num font-semibold" dir="ltr">{r.order.number}</span> · {r.reason}</span>
                <span className="flex items-center gap-3"><span className="num font-bold">{formatMoney(r.amount, lang)}</span>
                  <Link href={`/${lang}/admin/orders/returns?status=received`} className="font-semibold text-sun-text underline">{f.goRefund}</Link></span>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section>
        <h2 className="text-lg">{f.doneRefunds}</h2>
        {res.data.refunds.length === 0 ? <p className="mt-2 text-ink-muted">{f.noRows}</p> : (
          <ul className="mt-2 divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0 text-sm">
            {res.data.refunds.map((r) => (
              <li key={r.id} className="flex flex-wrap justify-between gap-2 p-3">
                <span>
                  {r.at && time.format(new Date(r.at))} · {t.orders.methods[r.method as keyof typeof t.orders.methods] ?? r.method} · {r.reason}
                  <span className="block text-xs text-ink-muted">
                    {r.credit_note && <span className="num" dir="ltr">{r.credit_note.number}</span>}
                    {r.invoice && <> · <span className="num" dir="ltr">{r.invoice.number}</span></>}{r.by && ` · ${r.by}`}
                  </span>
                </span>
                <span className="num font-bold">{formatMoney(r.amount, lang)}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

async function CreditNotes({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const res = await authed<CreditNoteRow[]>("admin", lang, "/admin/credit-notes");
  const rows = res.ok ? res.data : [];
  const f = t.admin.finance;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  if (rows.length === 0) return <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{f.noRows}</p>;
  return (
    <ul className="divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0 text-sm">
      {rows.map((c) => (
        <li key={c.id} className="flex flex-wrap justify-between gap-2 p-3">
          <span>
            <span className="num font-semibold" dir="ltr">{c.number}</span>
            {c.invoice && <> · <Link href={`/${lang}/admin/finance/invoices/${c.invoice.id}`} className="num underline" dir="ltr">{c.invoice.number}</Link></>}
            <span className="block text-xs text-ink-muted">{c.issued_at && time.format(new Date(c.issued_at))} · {c.reason}</span>
          </span>
          <span className="flex items-center gap-3">
            <span className="num font-bold">{c.total && formatMoney(c.total, lang)}</span>
            {c.pdf_url && <a href={c.pdf_url} target="_blank" rel="noopener noreferrer" className="underline">PDF</a>}
          </span>
        </li>
      ))}
    </ul>
  );
}
