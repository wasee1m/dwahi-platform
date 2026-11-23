import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { SERVICE_COLOR, type ServiceRow, type WorkOrderRow, type WorkOrderStatus } from "@/lib/service-types";

// A-09 أوامر العمل (FR-WO-01، US-33): بالحالة والنوع والفني والموعد ورقم بطاقة الاستلام
type Props = PageProps<"/[lang]/admin/work-orders">;
const FILTERS: (WorkOrderStatus | "open")[] = ["open", "unassigned", "scheduled", "in_progress", "on_hold", "work_completed", "verified", "closed", "cancelled"];
type Row = WorkOrderRow & { service_request: ServiceRow };

export default async function WorkOrdersPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.admin.service.workOrders}</h1>
        <Link href={`/${lang}/admin/dispatch`} className="rounded-sm border border-ink px-4 py-2 font-semibold hover:bg-sand-200">{t.admin.service.dispatch}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("service.triage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const sp = await searchParams;
  const status = FILTERS.includes(sp.status as WorkOrderStatus) ? (sp.status as string) : "open";
  const res = await authed<Row[]>("admin", lang, `/admin/work-orders?status=${status}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Record<string, number>;
  const a = t.admin.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={a.workOrders}>
        {FILTERS.map((x) => (
          <Link key={x} href={`?status=${x}`} aria-current={x === status ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${x === status ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {x === "open" ? a.filters.open : a.woStatuses[x]}{x !== "open" && counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{t.admin.orders.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-sand-200">
              <tr><th className="px-3 py-2 text-start">{a.workOrder}</th><th className="px-3 py-2 text-start">{a.request}</th><th className="px-3 py-2 text-start">{a.technicians}</th>
                <th className="px-3 py-2 text-start">{a.when}</th><th className="px-3 py-2 text-start">{t.admin.orders.status}</th><th className="px-3 py-2 text-start">{a.received}</th></tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((w) => (
                <tr key={w.id} className="align-top hover:bg-sand-100">
                  <td className="px-3 py-2"><Link href={`/${lang}/admin/work-orders/${w.id}`} className="num font-semibold text-sun-text hover:underline" dir="ltr">{w.number}</Link>
                    <span className="block text-xs text-ink-muted">{t.service.woTypes[w.type]}</span></td>
                  <td className="px-3 py-2"><span className="num" dir="ltr">{w.service_request.number}</span>
                    <span className="block text-xs text-ink-muted">{w.service_request.equipment?.name ?? t.service.components[w.service_request.component_type as keyof typeof t.service.components]}</span></td>
                  <td className="px-3 py-2">{w.technicians.map((x) => `${x.name}${x.role === "lead" ? " ★" : ""}`).join("، ") || "—"}</td>
                  <td className="px-3 py-2">{w.scheduled_start ? time.format(new Date(w.scheduled_start)) : "—"}</td>
                  <td className="px-3 py-2"><span className="font-semibold" style={{ color: SERVICE_COLOR[w.status] }}>{a.woStatuses[w.status]}</span></td>
                  <td className="px-3 py-2">{w.service_request.received ? "✓" : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
