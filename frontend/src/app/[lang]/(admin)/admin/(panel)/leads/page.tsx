import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import { LEAD_COLOR, type LeadRow } from "./shared";

// A-15 الاستفسارات (FR-CMS-05، US-43): الجديد أولاً، مع فلاتر الحالة والموضوع والإسناد
type Props = PageProps<"/[lang]/admin/leads">;

export default async function LeadsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl">{t.admin.leads.title}</h1>
        <p className="mt-1 text-ink-muted">{t.admin.leads.intro}</p>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("leads.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const pick = (k: string) => (typeof sp[k] === "string" && sp[k] ? (sp[k] as string) : undefined);
  const filters = { status: pick("status"), topic: pick("topic"), assigned: pick("assigned"), q: pick("q"), cursor: pick("cursor") };
  const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
  const res = await authed<LeadRow[]>("admin", lang, `/admin/leads${qs ? `?${qs}` : ""}`);
  const rows = res.ok ? res.data : [];
  const l = t.admin.leads;
  const c = t.admin.common;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const input = "rounded-sm border border-sand-300 bg-sand-0 px-3 py-2";

  return (
    <>
      <form method="get" className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <label className="text-sm"><span className="mb-1 block font-semibold">{c.search}</span><input name="q" defaultValue={filters.q} className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{l.status}</span>
          <select name="status" defaultValue={filters.status ?? ""} className={input}>
            <option value="">{c.all}</option>
            {(Object.keys(l.statuses) as LeadRow["status"][]).map((s) => <option key={s} value={s}>{l.statuses[s]}</option>)}
          </select>
        </label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{l.topic}</span>
          <select name="topic" defaultValue={filters.topic ?? ""} className={input}>
            <option value="">{c.all}</option>
            {(Object.keys(l.topics) as LeadRow["topic"][]).map((s) => <option key={s} value={s}>{l.topics[s]}</option>)}
          </select>
        </label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{l.assigned}</span>
          <select name="assigned" defaultValue={filters.assigned ?? ""} className={input}>
            <option value="">{c.all}</option>
            <option value="me">{l.assignedMe}</option>
            <option value="none">{l.unassigned}</option>
          </select>
        </label>
        <button className="rounded-sm bg-night px-5 py-2 font-bold text-on-night">{c.search}</button>
      </form>

      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{c.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[900px] text-sm">
            <thead className="bg-sand-200">
              <tr>{[l.received, l.name, l.phone, l.topic, l.branch, l.status, l.assigned].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((r) => (
                <tr key={r.id} className={`hover:bg-sand-100 ${r.status === "new" ? "font-semibold" : ""}`}>
                  <td className="px-4 py-2 whitespace-nowrap">{when.format(new Date(r.created_at))}</td>
                  <td className="px-4 py-2">
                    <Link href={`/${lang}/admin/leads/${r.id}`} className="underline-offset-4 hover:underline">{r.name}</Link>
                    {r.company && <span className="block text-xs font-normal text-ink-muted">{r.company}</span>}
                  </td>
                  <td className="num px-4 py-2 whitespace-nowrap" dir="ltr">{formatPhone(r.phone)}</td>
                  <td className="px-4 py-2">{l.topics[r.topic]}</td>
                  <td className="px-4 py-2">{r.branch ?? "—"}</td>
                  <td className="px-4 py-2" style={{ color: LEAD_COLOR[r.status] }}>{l.statuses[r.status]}</td>
                  <td className="px-4 py-2 font-normal">{r.assigned_to?.name ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
