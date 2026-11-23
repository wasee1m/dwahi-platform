import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone, listSep } from "@/lib/format";
import { age, STATUS_COLOR, type AdminRfqRow, type RfqStatus } from "./shared";

// A-05 طلبات العروض (FR-RFQ-01، US-32): وارد بتبويبات الحالة، والأقدم أولاً بين المفتوحة
type Props = PageProps<"/[lang]/admin/rfqs">;
const STATUSES: RfqStatus[] = ["submitted", "in_review", "quoted", "won", "lost", "cancelled"];

export default async function RfqsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.admin.rfqs.title}</h1>
        <Link href={`/${lang}/admin/rfqs/new`} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.admin.rfqs.new}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("quotation.send")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const pick = (k: string) => (typeof sp[k] === "string" && sp[k] ? (sp[k] as string) : undefined);
  const filters = { status: pick("status"), type: pick("type"), assigned: pick("assigned"), q: pick("q"), cursor: pick("cursor") };
  const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
  const res = await authed<AdminRfqRow[]>("admin", lang, `/admin/rfqs${qs ? `?${qs}` : ""}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Partial<Record<RfqStatus, number>>;
  const r = t.admin.rfqs;
  const c = t.admin.common;
  const tab = (key: string, label: string, href: string, active: boolean, n?: number) => (
    <Link key={key} href={href} aria-current={active ? "page" : undefined}
      className={`-mb-px border-b-2 px-3 py-2 font-semibold ${active ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
      {label}{n ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{n}</span> : null}
    </Link>
  );
  const open = (counts.submitted ?? 0) + (counts.in_review ?? 0) + (counts.quoted ?? 0);

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={r.title}>
        {tab("open", r.open, "?", !filters.status && !filters.assigned, open)}
        {tab("mine", r.mine, "?assigned=me", filters.assigned === "me")}
        {tab("none", r.unassigned, "?assigned=none", filters.assigned === "none")}
        {STATUSES.map((s) => tab(s, r.statuses[s], `?status=${s}`, filters.status === s, counts[s]))}
      </nav>
      <form method="get" className="flex flex-wrap items-end gap-3">
        {filters.status && <input type="hidden" name="status" value={filters.status} />}
        <input name="q" defaultValue={filters.q} placeholder={`${r.number} / ${r.customer}`} aria-label={c.search} className="w-64 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        <select name="type" defaultValue={filters.type ?? ""} aria-label={r.type} className="rounded-sm border border-sand-300 bg-sand-0 px-3 py-2">
          <option value="">{c.all}</option>
          {(Object.keys(r.types) as (keyof typeof r.types)[]).map((x) => <option key={x} value={x}>{r.types[x]}</option>)}
        </select>
        <button className="rounded-sm bg-night px-5 py-2 font-bold text-on-night">{c.search}</button>
      </form>

      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{c.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[960px] text-sm">
            <thead className="bg-sand-200">
              <tr>{[r.number, r.customer, r.items, r.status, r.assigned, r.total, r.age].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((x) => {
                const a = age(x.created_at);
                const late = x.status === "submitted" && a.unit === "days";
                return (
                  <tr key={x.id} className={`hover:bg-sand-100 ${x.status === "submitted" ? "font-semibold" : ""}`}>
                    <td className="px-4 py-2"><Link href={`/${lang}/admin/rfqs/${x.id}`} className="num underline-offset-4 hover:underline" dir="ltr">{x.number}</Link>
                      <span className="block text-xs font-normal text-ink-muted">{r.types[x.type]} · {r.sources[x.source as keyof typeof r.sources] ?? x.source}</span></td>
                    <td className="px-4 py-2">{x.customer.organization ?? x.customer.name}
                      {x.customer.phone && <span className="num block text-xs font-normal text-ink-muted" dir="ltr">{formatPhone(x.customer.phone)}</span>}</td>
                    <td className="max-w-64 truncate px-4 py-2 font-normal">{x.summary.join(listSep(lang))}</td>
                    <td className="px-4 py-2" style={{ color: STATUS_COLOR[x.status] }}>{r.statuses[x.status]}</td>
                    <td className="px-4 py-2 font-normal">{x.assigned_to?.name ?? "—"}</td>
                    <td className="num px-4 py-2 font-normal">{x.quotation ? formatMoney(x.quotation.total, lang) : "—"}</td>
                    <td className={`num px-4 py-2 ${late ? "text-danger" : "font-normal"}`}>{fill(r[a.unit], { n: String(a.n) })}</td>
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
