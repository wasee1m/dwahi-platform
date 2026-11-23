import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, listSep } from "@/lib/format";
import type { ReturnRow, ReturnStatus } from "@/lib/order-types";
import { ReturnActions } from "../OrderForms";

// A-07 تبويب الإرجاع (SM-3b، US-35): القرار والاستلام لموظف القطع، والاسترداد للمحاسب (FR-PAY-06)
type Props = PageProps<"/[lang]/admin/orders/returns">;
const FILTERS: (ReturnStatus | "open")[] = ["open", "requested", "approved", "received", "refunded", "rejected"];

export default async function ReturnsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <Link href={`/${lang}/admin/orders`} className="text-sm font-semibold text-ink-muted">← {t.admin.orders.title}</Link>
      <h1 className="text-3xl">{t.admin.orders.returnsTab}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const status = FILTERS.includes(sp.status as ReturnStatus) ? (sp.status as string) : "open";
  const [res, me] = await Promise.all([authed<ReturnRow[]>("admin", lang, `/admin/returns?status=${status}`), currentUser("admin", lang)]);
  const rows = res.ok ? res.data : [];
  const a = t.admin.orders;
  const st = t.orders.returnStatuses;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const money = (v: string) => formatMoney({ amount: v, currency: "OMR" }, lang);

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={a.returnsTab}>
        {FILTERS.map((x) => (
          <Link key={x} href={`?status=${x}`} aria-current={x === status ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${x === status ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {x === "open" ? a.active : st[x]}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{a.empty}</p> : (
        <ul className="space-y-3">
          {rows.map((r) => (
            <li key={r.id} className="rounded-md border border-sand-300 bg-sand-0 p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 text-sm">
                  <p className="font-bold">
                    {r.order && <Link href={`/${lang}/admin/orders/${r.order.id}`} className="num text-sun-text hover:underline" dir="ltr">{r.order.number}</Link>}
                    {" · "}{st[r.status]}{r.created_at && <span className="font-normal text-ink-muted"> · {date.format(new Date(r.created_at))}</span>}
                  </p>
                  <p className="mt-1">{r.items.map((i) => `${i.part_number ?? ""} ${i.name} × ${Number(i.quantity)}`).join(listSep(lang))}</p>
                  <p className="mt-1 text-ink-muted">{r.reason}</p>
                  {r.decision_note && <p className="mt-1">{r.decision_note}</p>}
                  {r.photos.length > 0 && (
                    <p className="mt-1 flex gap-2">{r.photos.map((p, n) => <a key={n} href={p} target="_blank" rel="noopener noreferrer" className="underline">{a.photo} {n + 1}</a>)}</p>
                  )}
                </div>
                {r.amount && <p className="num text-lg font-bold">{money(r.amount.total)}</p>}
              </div>
              <div className="mt-3">
                <ReturnActions key={r.status} lang={lang} t={a} id={r.id} status={r.status} items={r.items}
                  canDecide={me.permissions.includes("orders.deliver")} canRefund={me.permissions.includes("refunds.manage")} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
