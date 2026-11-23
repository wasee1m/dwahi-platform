import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, listSep } from "@/lib/format";
import { daysLeft, RFQ_COLOR, type RfqRow } from "./shared";

// C-08 عروض الأسعار (FR-RFQ-03، US-13): تبويبات بحسب ما ينتظره الطلب
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/quotes">;
const TABS = ["awaiting", "review", "done", "closed"] as const;
type Tab = (typeof TABS)[number];

export default async function QuotesPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.quotes.title}</h1>
        <Link href={`/${lang}/account/quotes/new`} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.quotes.new}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "awaiting";
  const account = await selectedAccount(await currentUser("customer", lang));
  const res = await authed<RfqRow[]>("customer", lang, `/me/rfqs?tab=${tab}&${account.query}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Partial<Record<Tab, number>>;
  const q = t.quotes;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={q.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {q.tabs[x]}{counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{q.empty}</p> : (
        <ul className="space-y-3">
          {rows.map((r) => {
            const left = r.quotation?.status === "sent" && r.quotation.valid_until ? daysLeft(r.quotation.valid_until) : null;
            return (
              <li key={r.id}>
                <Link href={`/${lang}/account/quotes/${r.id}`} className="block rounded-md border border-sand-300 bg-sand-0 p-4 hover:border-sun">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-bold"><span className="num" dir="ltr">{r.number}</span> · {q.types[r.type]}</p>
                      <p className="mt-1 truncate text-sm text-ink-muted">{r.summary.join(listSep(lang))}</p>
                      <p className="mt-1 text-xs text-ink-muted">{date.format(new Date(r.created_at))}</p>
                    </div>
                    <div className="text-end">
                      <p className="font-semibold" style={{ color: RFQ_COLOR[r.status] }}>{q.rfqStatuses[r.status]}</p>
                      {r.quotation && <p className="num mt-1 text-lg font-bold">{formatMoney(r.quotation.total, lang)}</p>}
                      {left !== null && <p className="text-xs font-semibold text-[var(--status-waiting)]">{left === 0 ? q.lastDay : fill(q.daysLeft, { n: String(left) })}</p>}
                    </div>
                  </div>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
