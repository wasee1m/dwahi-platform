import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney, formatPhone } from "@/lib/format";
import { ORDER_COLOR, type OrderRow, type OrderStatus } from "@/lib/order-types";

// A-07 طلبات القطع (FR-ORD-04، US-35): أعمدة الحالة، والأقرب مهلةً ثم الأقدم أولاً، وبحث بالرقم أو العميل
type Props = PageProps<"/[lang]/admin/orders">;
const BOARD: OrderStatus[] = ["confirmed", "processing", "ready_for_pickup", "out_for_delivery"];
const ALL: (OrderStatus | "active")[] = ["active", ...BOARD, "delivered", "cancelled"];

export default async function OrdersPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const o = t.admin.orders;
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{o.title}</h1>
        <Link href={`/${lang}/admin/orders/returns`} className="rounded-sm border border-ink px-4 py-2 font-semibold hover:bg-sand-200">{o.returnsTab}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const status = ALL.includes(sp.status as OrderStatus) ? (sp.status as string) : "active";
  const q = typeof sp.q === "string" ? sp.q : "";
  const qs = new URLSearchParams({ status, ...(q ? { q } : {}) });
  const res = await authed<OrderRow[]>("admin", lang, `/admin/orders?${qs.toString()}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Partial<Record<OrderStatus, number>>;
  const o = t.admin.orders;
  const st = t.orders.statuses;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <form method="get" role="search" className="flex max-w-xl gap-2">
        <input type="hidden" name="status" value={status} />
        <label htmlFor="q" className="sr-only">{o.search}</label>
        <input id="q" name="q" defaultValue={q} placeholder={o.search} className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        <button className="rounded-sm bg-night px-4 py-2 font-semibold text-on-night">{o.find}</button>
      </form>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={o.title}>
        {ALL.map((x) => (
          <Link key={x} href={`?status=${x}${q ? `&q=${encodeURIComponent(q)}` : ""}`} aria-current={x === status ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${x === status ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {x === "active" ? o.active : st[x]}
            {x !== "active" && counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{o.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-sand-200">
              <tr>
                <th className="px-3 py-2 text-start">{o.number}</th>
                <th className="px-3 py-2 text-start">{o.customer}</th>
                <th className="px-3 py-2 text-start">{o.fulfillment}</th>
                <th className="px-3 py-2 text-start">{o.status}</th>
                <th className="px-3 py-2 text-start">{o.deadline}</th>
                <th className="px-3 py-2 text-end">{o.total}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((r) => (
                <tr key={r.id} className="align-top hover:bg-sand-100">
                  <td className="px-3 py-2">
                    <Link href={`/${lang}/admin/orders/${r.id}`} className="num font-semibold text-sun-text hover:underline" dir="ltr">{r.number}</Link>
                    <span className="block text-xs text-ink-muted">{r.created_at && time.format(new Date(r.created_at))} · {fill(t.orders.itemsCount, { n: String(r.items_count ?? 0) })}</span>
                    {r.from_quote && <span className="block text-xs text-ink-muted">{t.orders.fromQuote}</span>}
                  </td>
                  <td className="px-3 py-2">
                    {r.customer?.organization ?? r.customer?.name}
                    {r.customer?.phone && <span className="num block text-xs text-ink-muted" dir="ltr">{formatPhone(r.customer.phone)}</span>}
                  </td>
                  <td className="px-3 py-2">{t.orders.fulfillment[r.fulfillment]}{r.branch && <span className="block text-xs text-ink-muted">{r.branch.name}</span>}</td>
                  <td className="px-3 py-2">
                    <span className="font-semibold" style={{ color: ORDER_COLOR[r.status] }}>{st[r.status]}</span>
                    {r.needs_phone_confirmation && <span className="mt-1 block text-xs font-semibold text-[var(--status-waiting)]">{o.needsPhone}</span>}
                  </td>
                  <td className="px-3 py-2">{r.pickup_deadline_at ? time.format(new Date(r.pickup_deadline_at)) : "—"}</td>
                  <td className="num px-3 py-2 text-end">{r.total && formatMoney(r.total, lang)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
