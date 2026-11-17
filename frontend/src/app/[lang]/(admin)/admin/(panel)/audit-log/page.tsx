import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";

// A-19 سجل التدقيق (FR-ADM-04، US-44): بحث بالكيان والمنفّذ والإجراء والفترة، والقيم قبل/بعد. للقراءة فقط.
type Row = {
  id: number; action: string; entity_type: string; entity_id: string | null; actor: { id: string; name: string } | null;
  before: Record<string, unknown> | null; after: Record<string, unknown> | null; trace_id: string | null; created_at: string;
};
type Props = PageProps<"/[lang]/admin/audit-log">;

export default async function AuditLogPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl">{t.admin.audit.title}</h1>
        <p className="mt-1 text-ink-muted">{t.admin.audit.intro}</p>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Log lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

function diff(before: Record<string, unknown> | null, after: Record<string, unknown> | null) {
  const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];
  return keys
    .filter((k) => JSON.stringify(before?.[k]) !== JSON.stringify(after?.[k]))
    .map((k) => ({ key: k, before: before?.[k], after: after?.[k] }));
}
const show = (v: unknown) => (v === undefined ? "—" : typeof v === "string" ? v : JSON.stringify(v));

async function Log({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("audit.view")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const pick = (k: string) => (typeof sp[k] === "string" && sp[k] ? (sp[k] as string) : undefined);
  const filters = { action: pick("action"), entity_type: pick("entity_type"), entity_id: pick("entity_id"), from: pick("from"), to: pick("to"), cursor: pick("cursor") };
  const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
  const res = await authed<Row[]>("admin", lang, `/admin/audit-logs${qs ? `?${qs}` : ""}`);
  const raw = res.ok ? (res.data as unknown as Row[]) : [];
  const a = t.admin.audit;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "medium", timeZone: "Asia/Muscat" });
  const input = "rounded-sm border border-sand-300 bg-sand-0 px-3 py-2";

  return (
    <>
      <form method="get" className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <label className="text-sm"><span className="mb-1 block font-semibold">{a.action}</span><input name="action" defaultValue={filters.action} dir="ltr" placeholder="staff." className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{a.entity}</span><input name="entity_type" defaultValue={filters.entity_type} dir="ltr" placeholder="user" className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-semibold">ID</span><input name="entity_id" defaultValue={filters.entity_id} dir="ltr" className={`${input} w-72`} /></label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{a.from}</span><input type="date" name="from" defaultValue={filters.from} className={input} /></label>
        <label className="text-sm"><span className="mb-1 block font-semibold">{a.to}</span><input type="date" name="to" defaultValue={filters.to} className={input} /></label>
        <button className="rounded-sm bg-sun px-5 py-2 font-bold text-on-sun">{a.filter}</button>
      </form>

      {raw.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{a.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[900px] text-sm">
            <thead className="bg-sand-200">
              <tr>{[a.when, a.actor, a.action, a.entity, a.changes].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {raw.map((r) => (
                <tr key={r.id} className="align-top">
                  <td className="px-4 py-3 whitespace-nowrap">{when.format(new Date(r.created_at))}</td>
                  <td className="px-4 py-3">{r.actor?.name ?? "—"}</td>
                  <td className="num px-4 py-3" dir="ltr">{r.action}</td>
                  <td className="px-4 py-3"><span className="num" dir="ltr">{r.entity_type}</span><br /><span className="num text-xs text-ink-muted" dir="ltr">{r.entity_id}</span></td>
                  <td className="px-4 py-3">
                    <ul className="space-y-1">
                      {diff(r.before, r.after).map((d) => (
                        <li key={d.key} dir="ltr" className="num text-xs">
                          <strong>{d.key}</strong>: <span className="text-danger line-through">{show(d.before)}</span> → <span className="text-success">{show(d.after)}</span>
                        </li>
                      ))}
                    </ul>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {raw.length >= 50 && (
        <Link href={`?${new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v) as [string, string][]), cursor: String(raw[raw.length - 1].id) })}`}
          className="inline-block rounded-sm border border-ink px-5 py-2 font-semibold">{a.more}</Link>
      )}
    </>
  );
}
