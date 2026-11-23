import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { DailyBars } from "@/components/DailyBars";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Money } from "@/lib/types";

// A-01 لوحة المؤشرات (FR-RPT-01، US-41): بطاقات قابلة للنقر حسب الدور، ورسم يومي، و"تحتاج انتباهاً"، وفلترة الفترة والفرع
type Props = PageProps<"/[lang]/admin">;
type Dashboard = {
  period: { from: string; to: string }; branch_id: string | null; branches: { id: string; name: string | null }[];
  sections: {
    quotes?: { rfqs: number; median_response_hours: number | null; acceptance_rate: number | null; awaiting_quote: number };
    service?: { open: number; sla_breached: number; created: number; platform_share: number | null; work_orders_today: number; in_progress: number; on_hold: number; ready_for_pickup: number; documented_rate: number | null };
    parts?: { orders_delivered: number; sales: Money; open_orders: number; stockouts: number };
    revenue?: { invoiced: Money; collected: Money; by_line: { line: string; total: Money }[] };
    ratings?: { average: number | null; count: number };
  };
  series: { date: string; rfqs: number; revenue: string }[];
  attention: {
    sla_breached?: { id: string; number: string; urgency: string; since: string | null }[];
    on_hold?: { id: string; number: string; reason: string | null; since: string | null }[];
    expiring_today?: { id: string; number: string | null; total: Money }[];
  };
};

export default function AdminHome({ params, searchParams }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <View params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

const day = /^\d{4}-\d{2}-\d{2}$/;

async function View({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const d = t.admin.dashboard;
  const me = await currentUser("admin", lang);
  const q = new URLSearchParams();
  for (const k of ["from", "to"] as const) if (typeof sp[k] === "string" && day.test(sp[k])) q.set(k, sp[k]);
  if (typeof sp.branch_id === "string" && /^[0-9a-f-]{36}$/i.test(sp.branch_id)) q.set("branch_id", sp.branch_id);
  const can = me.permissions.includes("reports.view") || me.permissions.includes("reports.own");
  const res = can ? await authed<Dashboard>("admin", lang, `/admin/dashboard?${q}`) : null;

  if (!res?.ok) {
    return (
      <>
        <h1 className="text-3xl">{t.admin.panel}</h1>
        <Notice tone="info">{d.noSections}</Notice>
      </>
    );
  }
  const x = res.data;
  const s = x.sections;
  const num = new Intl.NumberFormat(intlLocale(lang));
  const pct = (v: number | null) => (v === null ? "—" : `${num.format(v)}%`);
  const local = (iso: string) => new Date(new Date(iso).getTime() + 4 * 3_600_000).toISOString().slice(0, 10);
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const href = (path: string) => `/${lang}${path}`;

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-3xl">{d.title}</h1>
        <Link href={href(`/admin/reports?${q}`)} className="text-sm font-semibold underline">{d.reports}</Link>
      </div>

      <form className="flex flex-wrap items-end gap-3 text-sm" aria-label={d.title}>
        <label className="grid gap-1"><span>{d.from}</span>
          <input type="date" name="from" defaultValue={local(x.period.from)} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-1.5" /></label>
        <label className="grid gap-1"><span>{d.to}</span>
          <input type="date" name="to" defaultValue={local(x.period.to)} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-1.5" /></label>
        {x.branches.length > 0 && (
          <label className="grid gap-1"><span>{d.branch}</span>
            <select name="branch_id" defaultValue={x.branch_id ?? ""} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-1.5">
              <option value="">{d.allBranches}</option>
              {x.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select></label>
        )}
        <button className="rounded-sm bg-night px-4 py-2 font-semibold text-on-night">{d.apply}</button>
        <Link href={href("/admin")} className="px-2 py-2 text-ink-muted">{d.thisMonth}</Link>
      </form>

      {s.quotes && (
        <Group title={d.quotes}>
          <Kpi label={d.rfqs} value={num.format(s.quotes.rfqs)} href={href("/admin/rfqs")} />
          <Kpi label={d.medianResponse} value={s.quotes.median_response_hours === null ? "—" : num.format(s.quotes.median_response_hours)} unit={s.quotes.median_response_hours === null ? undefined : d.hours} href={href(`/admin/reports?report=quotes&${q}`)} />
          <Kpi label={d.acceptance} value={pct(s.quotes.acceptance_rate)} href={href(`/admin/reports?report=quotes&${q}`)} />
          <Kpi label={d.awaitingQuote} value={num.format(s.quotes.awaiting_quote)} href={href("/admin/rfqs")} tone={s.quotes.awaiting_quote > 0 ? "warn" : undefined} />
        </Group>
      )}
      {s.service && (
        <Group title={d.service}>
          <Kpi label={d.open} value={num.format(s.service.open)} href={href("/admin/service-requests")} />
          <Kpi label={d.slaBreached} value={num.format(s.service.sla_breached)} href={href("/admin/service-requests")} tone={s.service.sla_breached > 0 ? "bad" : undefined} />
          <Kpi label={d.workOrdersToday} value={num.format(s.service.work_orders_today)} href={href("/admin/dispatch")} />
          <Kpi label={d.onHold} value={num.format(s.service.on_hold)} href={href("/admin/work-orders?status=on_hold")} tone={s.service.on_hold > 0 ? "warn" : undefined} />
          <Kpi label={d.readyForPickup} value={num.format(s.service.ready_for_pickup)} href={href("/admin/workshop-desk")} />
          <Kpi label={d.platformShare} value={pct(s.service.platform_share)} href={href(`/admin/reports?report=service&${q}`)} />
          <Kpi label={d.documented} value={pct(s.service.documented_rate)} href={href("/admin/work-orders?status=closed")} />
        </Group>
      )}
      {s.parts && (
        <Group title={d.parts}>
          <Kpi label={d.ordersDelivered} value={num.format(s.parts.orders_delivered)} href={href("/admin/orders")} />
          <Kpi label={d.sales} value={formatMoney(s.parts.sales, lang)} href={href(`/admin/reports?report=parts&${q}`)} />
          <Kpi label={d.openOrders} value={num.format(s.parts.open_orders)} href={href("/admin/orders")} />
          <Kpi label={d.stockouts} value={num.format(s.parts.stockouts)} href={href(`/admin/reports?report=stockouts&${q}`)} tone={s.parts.stockouts > 0 ? "warn" : undefined} />
        </Group>
      )}
      {s.revenue && (
        <Group title={d.revenue}>
          <Kpi label={d.invoiced} value={formatMoney(s.revenue.invoiced, lang)} href={href(`/admin/reports?report=revenue&${q}`)} />
          <Kpi label={d.collected} value={formatMoney(s.revenue.collected, lang)} href={href("/admin/finance")} />
          {s.revenue.by_line.map((l) => (
            <Kpi key={l.line} label={d.lines[l.line as keyof typeof d.lines] ?? l.line} value={formatMoney(l.total, lang)} href={href(`/admin/reports?report=revenue&${q}`)} />
          ))}
        </Group>
      )}
      {s.ratings && (
        <Group title={d.ratings}>
          <Kpi label={d.average} value={s.ratings.average === null ? "—" : `${num.format(s.ratings.average)} / 5`} sub={`${num.format(s.ratings.count)} ${d.reviews}`} />
        </Group>
      )}

      {x.series.length > 1 && (s.quotes || s.revenue) && (
        <div className="grid gap-4 lg:grid-cols-2">
          {s.quotes && <DailyBars title={d.rfqsChart} kind="count" lang={lang} dayLabel={d.day} tableLabel={d.showTable}
            points={x.series.map((p) => ({ date: p.date, value: p.rfqs }))} />}
          {s.revenue && <DailyBars title={d.revenueChart} kind="money" lang={lang} dayLabel={d.day} tableLabel={d.showTable}
            points={x.series.map((p) => ({ date: p.date, value: Number(p.revenue) }))} />}
        </div>
      )}

      {Object.keys(x.attention).length > 0 && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
          <h2 className="text-lg">{d.attention}</h2>
          <div className="mt-3 grid gap-6 md:grid-cols-3">
            {x.attention.sla_breached && (
              <Attention title={d.attSla} empty={d.nothing} items={x.attention.sla_breached.map((r) => ({
                key: r.id, href: href(`/admin/service-requests/${r.id}`), label: r.number,
                sub: `${t.admin.service.priorities[r.urgency as keyof typeof t.admin.service.priorities] ?? r.urgency}${r.since ? ` · ${d.since} ${time.format(new Date(r.since))}` : ""}`,
              }))} />
            )}
            {x.attention.on_hold && (
              <Attention title={d.attHold} empty={d.nothing} items={x.attention.on_hold.map((r) => ({
                key: r.id, href: href(`/admin/work-orders/${r.id}`), label: r.number,
                sub: `${r.reason ? t.admin.service.holdReasons[r.reason as keyof typeof t.admin.service.holdReasons] ?? r.reason : ""}${r.since ? ` · ${d.since} ${time.format(new Date(r.since))}` : ""}`,
              }))} />
            )}
            {x.attention.expiring_today && (
              <Attention title={d.attExpiring} empty={d.nothing} items={x.attention.expiring_today.map((r) => ({
                key: r.id + (r.number ?? ""), href: href(`/admin/rfqs/${r.id}`), label: r.number ?? "", sub: formatMoney(r.total, lang),
              }))} />
            )}
          </div>
        </section>
      )}
    </>
  );
}

function Group({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section aria-label={title}>
      <h2 className="mb-2 text-lg">{title}</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div>
    </section>
  );
}

/** بطاقة رقم قابلة للنقر. اللون يرافقه نص الحالة في الرقم نفسه، فلا يُعتمد على اللون وحده (NFR-15). */
function Kpi({ label, value, unit, href, sub, tone }: { label: string; value: string; unit?: string; href?: string; sub?: string; tone?: "warn" | "bad" }) {
  const border = tone === "bad" ? "border-[var(--status-negative)]" : tone === "warn" ? "border-[var(--status-waiting)]" : "border-sand-300";
  const body = (
    <>
      <span className="block text-sm text-ink-muted">{label}</span>
      <span className="mt-1 block text-2xl font-bold"><span className="num">{value}</span>{unit && <span className="text-base font-semibold"> {unit}</span>}</span>
      {sub && <span className="block text-xs text-ink-muted">{sub}</span>}
    </>
  );
  const cls = `block rounded-md border-2 ${border} bg-sand-0 p-4`;
  return href ? <Link href={href} className={`${cls} hover:border-sun focus-visible:border-sun`}>{body}</Link> : <div className={cls}>{body}</div>;
}

function Attention({ title, empty, items }: { title: string; empty: string; items: { key: string; href: string; label: string; sub: string }[] }) {
  return (
    <div>
      <h3 className="font-semibold">{title}</h3>
      {items.length === 0 ? <p className="mt-1 text-ink-muted">{empty}</p> : (
        <ul className="mt-1 space-y-1">
          {items.map((i) => (
            <li key={i.key}><Link href={i.href} className="num font-semibold underline" dir="ltr">{i.label}</Link>
              {i.sub && <span className="block text-xs text-ink-muted">{i.sub}</span>}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
