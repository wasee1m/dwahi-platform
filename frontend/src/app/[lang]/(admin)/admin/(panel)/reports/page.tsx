import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";

// A-16 التقارير الجاهزة (FR-RPT-01، FR-RPT-03، US-41): العروض، والصيانة، والقطع الأكثر مبيعاً وما نفد، والإيرادات، مع التصدير
type Props = PageProps<"/[lang]/admin/reports">;
type Report = {
  key: string; columns: string[]; headers: Record<string, string>;
  rows: Record<string, string | number | boolean | null>[]; summary: Record<string, unknown>;
};

const KEYS = ["quotes", "service", "parts", "stockouts", "revenue"] as const;
const ABILITY: Record<(typeof KEYS)[number], string[]> = {
  quotes: ["quotation.send"], service: ["service.triage", "workorder.verify"], parts: ["orders.deliver", "parts.manage"],
  stockouts: ["orders.deliver", "parts.manage"], revenue: ["invoices.manage", "reports.view"],
};

export default function ReportsPage({ params, searchParams }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[560px]" />}>
        <View params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function View({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const r = t.admin.reports;
  const me = await currentUser("admin", lang);
  const reporter = me.permissions.includes("reports.view") || me.permissions.includes("reports.own");
  const allowed = KEYS.filter((k) => reporter && ABILITY[k].some((a) => me.permissions.includes(a)));
  if (allowed.length === 0) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const key = allowed.find((k) => k === sp.report) ?? allowed[0];

  const filters = new URLSearchParams();
  for (const k of ["from", "to"] as const) if (typeof sp[k] === "string" && /^\d{4}-\d{2}-\d{2}$/.test(sp[k])) filters.set(k, sp[k]);
  if (typeof sp.branch_id === "string" && /^[0-9a-f-]{36}$/i.test(sp.branch_id)) filters.set("branch_id", sp.branch_id);
  const res = await authed<Report>("admin", lang, `/admin/reports/${key}?${filters}`);
  if (!res.ok) return <Notice tone="danger">{res.error.message}</Notice>;
  const data = res.data;
  const num = new Intl.NumberFormat(intlLocale(lang));
  const cell = (v: string | number | boolean | null) => (v === null ? "—" : typeof v === "boolean" ? (v ? r.yes : r.no) : typeof v === "number" ? num.format(v) : v);
  const link = (k: string) => `/${lang}/admin/reports?${new URLSearchParams({ ...Object.fromEntries(filters), report: k })}`;
  const exportHref = (format: string) => `/${lang}/admin/reports/export?${new URLSearchParams({ ...Object.fromEntries(filters), report: key, format })}`;
  const s = data.summary;

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-3xl">{r.title}</h1>
        <Link href={`/${lang}/admin?${filters}`} className="text-sm font-semibold underline">{t.admin.dashboard.title}</Link>
      </div>
      <form className="flex flex-wrap items-end gap-3 text-sm" aria-label={r.title}>
        <input type="hidden" name="report" value={key} />
        <label className="grid gap-1"><span>{t.admin.dashboard.from}</span>
          <input type="date" name="from" defaultValue={filters.get("from") ?? ""} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-1.5" /></label>
        <label className="grid gap-1"><span>{t.admin.dashboard.to}</span>
          <input type="date" name="to" defaultValue={filters.get("to") ?? ""} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-1.5" /></label>
        {filters.get("branch_id") && <input type="hidden" name="branch_id" value={filters.get("branch_id")!} />}
        <button className="rounded-sm bg-night px-4 py-2 font-semibold text-on-night">{t.admin.dashboard.apply}</button>
      </form>

      <nav aria-label={r.title} className="flex flex-wrap gap-2">
        {allowed.map((k) => (
          <Link key={k} href={link(k)} aria-current={k === key ? "page" : undefined}
            className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${k === key ? "border-night bg-night text-on-night" : "border-sand-300 bg-sand-0"}`}>{r[k]}</Link>
        ))}
      </nav>

      <section className="rounded-md border border-sand-300 bg-sand-0 p-5 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg">{r.summary} · <span className="num">{num.format(data.rows.length)}</span> {r.rows}</h2>
          <div className="flex gap-2">
            <a href={exportHref("xlsx")} className="rounded-sm bg-sun px-4 py-2 font-bold text-on-sun">{r.exportXlsx}</a>
            <a href={exportHref("csv")} className="rounded-sm border border-sand-300 px-4 py-2 font-semibold">{r.exportCsv}</a>
          </div>
        </div>
        <dl className="mt-3 grid gap-2 sm:grid-cols-3">
          {typeof s.median_response_hours !== "undefined" && <Stat label={r.medianResponse} value={s.median_response_hours === null ? "—" : num.format(Number(s.median_response_hours))} />}
          {typeof s.acceptance_rate !== "undefined" && <Stat label={r.acceptance} value={s.acceptance_rate === null ? "—" : `${num.format(Number(s.acceptance_rate))}%`} />}
          {typeof s.sla_breached !== "undefined" && <Stat label={r.slaBreached} value={num.format(Number(s.sla_breached))} />}
          {typeof s.total !== "undefined" && <Stat label={r.total} value={`${s.total}`} />}
          {typeof s.collected !== "undefined" && <Stat label={r.collected} value={`${s.collected}`} />}
          {typeof s.revenue !== "undefined" && <Stat label={r.total} value={`${s.revenue}`} />}
        </dl>
        {s.open_by_age !== undefined && (
          <p className="mt-2">{r.openByAge}: {Object.entries(s.open_by_age as Record<string, number>).map(([k, v]) => `${k}: ${num.format(v)}`).join(" · ")}</p>
        )}
        {s.lost_reasons !== undefined && Object.keys(s.lost_reasons as object).length > 0 && (
          <p className="mt-2">{r.lostReasons}: {Object.entries(s.lost_reasons as Record<string, number>).map(([k, v]) => `${k} (${num.format(v)})`).join(" · ")}</p>
        )}
      </section>

      {data.rows.length === 0 ? <p className="text-ink-muted">{r.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full text-sm">
            <caption className="sr-only">{r[key]}</caption>
            <thead className="bg-sand-100">
              <tr>{data.columns.map((c) => <th key={c} scope="col" className="px-3 py-2 text-start font-semibold">{data.headers[c] ?? c}</th>)}</tr>
            </thead>
            <tbody>
              {data.rows.slice(0, 200).map((row, n) => (
                <tr key={n} className="border-t border-sand-200">
                  {data.columns.map((c) => <td key={c} className={`px-3 py-2 ${typeof row[c] === "number" || c === "number" || c === "part_number" ? "num" : ""}`}>{cell(row[c])}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
          {data.rows.length > 200 && <p className="p-3 text-ink-muted">{r.shown}</p>}
        </div>
      )}
    </>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-ink-muted">{label}</dt>
      <dd className="num text-xl font-bold">{value}</dd>
    </div>
  );
}
