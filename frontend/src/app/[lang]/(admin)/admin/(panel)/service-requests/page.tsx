import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import { SERVICE_COLOR, type ServiceRow } from "@/lib/service-types";

// A-08 فرز طلبات الصيانة (FR-SRV-02، US-31): submitted بالمهلة الأقرب والعاجل أولاً، مع عداد ولون تحذير
type Props = PageProps<"/[lang]/admin/service-requests">;
const FILTERS = ["triage", "open", "approved", "all"] as const;

export default async function ServiceRequestsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.admin.service.title}</h1>
        <Link href={`/${lang}/admin/workshop-desk`} className="rounded-sm border border-ink px-4 py-2 font-semibold hover:bg-sand-200">{t.admin.service.desk}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

/** المتبقي من المهلة، ولون التحذير عند بقاء أقل من 25% (US-31) */
function slaLeft(due: string | null, created: string | null): { text: string; tone: string } {
  if (!due || !created) return { text: "—", tone: "" };
  const left = Date.parse(due) - Date.now();
  const total = Math.max(1, Date.parse(due) - Date.parse(created));
  const minutes = Math.round(Math.abs(left) / 60_000);
  const text = `${left < 0 ? "−" : ""}${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")}`;
  return { text, tone: left < 0 ? "text-danger font-bold" : left / total < 0.25 ? "text-[var(--status-waiting)] font-bold" : "" };
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("service.triage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const sp = await searchParams;
  const status = FILTERS.includes(sp.status as (typeof FILTERS)[number]) ? (sp.status as string) : "triage";
  const q = typeof sp.q === "string" ? sp.q : "";
  const res = await authed<ServiceRow[]>("admin", lang, `/admin/service-requests?${new URLSearchParams({ status, ...(q ? { q } : {}) })}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Record<string, number>;
  const a = t.admin.service;
  const s = t.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <form method="get" role="search" className="flex max-w-xl gap-2">
        <input type="hidden" name="status" value={status} />
        <input name="q" defaultValue={q} placeholder={a.search} aria-label={a.search} className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        <button className="rounded-sm bg-night px-4 py-2 font-semibold text-on-night">{t.admin.orders.find}</button>
      </form>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={a.title}>
        {FILTERS.map((x) => (
          <Link key={x} href={`?status=${x}`} aria-current={x === status ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${x === status ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {a.filters[x]}{counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{t.admin.orders.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-sand-200">
              <tr>
                <th className="px-3 py-2 text-start">{a.request}</th><th className="px-3 py-2 text-start">{t.admin.orders.customer}</th>
                <th className="px-3 py-2 text-start">{s.item}</th><th className="px-3 py-2 text-start">{t.admin.orders.status}</th>
                <th className="px-3 py-2 text-start">{a.sla}</th><th className="px-3 py-2 text-start">{a.received}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((r) => {
                const sla = r.status === "submitted" ? slaLeft(r.sla_due_at, r.created_at) : { text: "—", tone: "" };
                return (
                  <tr key={r.id} className="align-top hover:bg-sand-100">
                    <td className="px-3 py-2">
                      <Link href={`/${lang}/admin/service-requests/${r.id}`} className="num font-semibold text-sun-text hover:underline" dir="ltr">{r.number}</Link>
                      {r.urgency === "urgent" && <span className="ms-2 rounded-sm bg-danger px-1.5 text-xs font-bold text-white">{s.urgencies.urgent}</span>}
                      <span className="block text-xs text-ink-muted">{r.created_at && time.format(new Date(r.created_at))} · {a.sources[r.source as keyof typeof a.sources] ?? r.source}</span>
                    </td>
                    <td className="px-3 py-2">{r.customer?.organization ?? r.customer?.name}
                      {r.customer?.phone && <span className="num block text-xs text-ink-muted" dir="ltr">{formatPhone(r.customer.phone)}</span>}</td>
                    <td className="px-3 py-2">{r.equipment?.name ?? s.components[r.component_type as keyof typeof s.components]}</td>
                    <td className="px-3 py-2"><span className="font-semibold" style={{ color: SERVICE_COLOR[r.status] }}>{a.statuses[r.status]}</span></td>
                    <td className={`num px-3 py-2 ${sla.tone}`} dir="ltr">{sla.text}</td>
                    <td className="px-3 py-2">{r.received ? "✓" : "—"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
