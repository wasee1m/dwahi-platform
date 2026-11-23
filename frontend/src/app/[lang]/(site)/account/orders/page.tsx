import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { ORDER_COLOR, type OrderRow } from "@/lib/order-types";

// C-13 طلبات القطع (FR-ORD-03، US-15)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/orders">;
const TABS = ["open", "delivered", "cancelled"] as const;
type Tab = (typeof TABS)[number];

export default async function OrdersPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.orders.title}</h1>
        <Link href={`/${lang}/cart`} className="rounded-sm border border-ink px-5 py-2.5 font-bold hover:bg-sand-200">{t.account.nav.cart}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "open";
  const account = await selectedAccount(await currentUser("customer", lang));
  const res = await authed<OrderRow[]>("customer", lang, `/me/orders?tab=${tab}&${account.query}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Partial<Record<Tab, number>>;
  const o = t.orders;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={o.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {o.tabs[x]}{counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{o.empty}</p> : (
        <ul className="space-y-3">
          {rows.map((r) => (
            <li key={r.id}>
              <Link href={`/${lang}/account/orders/${r.id}`} className="block rounded-md border border-sand-300 bg-sand-0 p-4 hover:border-sun">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-bold"><span className="num" dir="ltr">{r.number}</span> · {o.fulfillment[r.fulfillment]}</p>
                    <p className="mt-1 truncate text-sm text-ink-muted">{r.summary.join("، ")}</p>
                    <p className="mt-1 text-xs text-ink-muted">
                      {r.created_at && date.format(new Date(r.created_at))} · {fill(o.itemsCount, { n: String(r.items_count ?? 0) })}
                      {r.account && <> · {r.account.name}</>}
                    </p>
                  </div>
                  <div className="text-end">
                    <p className="font-semibold" style={{ color: ORDER_COLOR[r.status] }}>{o.statuses[r.status]}</p>
                    {r.total && <p className="num mt-1 text-lg font-bold">{formatMoney(r.total, lang)}</p>}
                  </div>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
