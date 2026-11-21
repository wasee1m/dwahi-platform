import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Money } from "@/lib/types";

// A-02 المعدات المعروضة (FR-CAT-06، US-36): قائمة بالبحث والحالة والنوع
type Row = {
  id: string; slug: string; kind: "machine" | "component" | "scrap"; title_ar: string; title_en: string; model: string | null;
  condition: "new" | "used" | "rebuilt" | "for_parts"; price: Money | null; price_visibility: string; branch: string | null;
  status: "draft" | "published" | "reserved" | "sold" | "archived"; is_featured: boolean; cover_url: string | null;
};
type Props = PageProps<"/[lang]/admin/equipment">;

const STATUS_COLOR: Record<Row["status"], string> = {
  draft: "var(--status-waiting)", published: "var(--status-success)", reserved: "var(--status-active)", sold: "var(--status-closed)", archived: "var(--status-neutral)",
};

export default async function EquipmentAdminPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.admin.equipment.title}</h1>
        <Link href={`/${lang}/admin/equipment/new`} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.admin.equipment.new}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("catalog.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const pick = (k: string) => (typeof sp[k] === "string" && sp[k] ? (sp[k] as string) : undefined);
  const filters = { q: pick("q"), status: pick("status"), kind: pick("kind"), cursor: pick("cursor") };
  const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
  const res = await authed<Row[]>("admin", lang, `/admin/equipment${qs ? `?${qs}` : ""}`);
  const rows = res.ok ? res.data : [];
  const e = t.admin.equipment;
  const c = t.admin.common;
  const input = "rounded-sm border border-sand-300 bg-sand-0 px-3 py-2";

  return (
    <>
      <form method="get" className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <label className="text-sm"><span className="mb-1 block font-semibold">{c.search}</span><input name="q" defaultValue={filters.q} className={input} /></label>
        <label className="text-sm">
          <span className="mb-1 block font-semibold">{e.status}</span>
          <select name="status" defaultValue={filters.status ?? ""} className={input}>
            <option value="">{c.all}</option>
            {(Object.keys(e.statuses) as Row["status"][]).map((s) => <option key={s} value={s}>{e.statuses[s]}</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-semibold">{e.kind}</span>
          <select name="kind" defaultValue={filters.kind ?? ""} className={input}>
            <option value="">{c.all}</option>
            {(Object.keys(e.kinds) as Row["kind"][]).map((k) => <option key={k} value={k}>{e.kinds[k]}</option>)}
          </select>
        </label>
        <button className="rounded-sm bg-night px-5 py-2 font-bold text-on-night">{c.search}</button>
      </form>

      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{c.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[900px] text-sm">
            <thead className="bg-sand-200">
              <tr>{[e.cols.photo, e.cols.title, e.cols.model, e.cols.condition, e.cols.price, e.cols.status].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((r) => (
                <tr key={r.id} className="align-middle hover:bg-sand-100">
                  <td className="px-4 py-2">
                    {r.cover_url ? <Image src={r.cover_url} alt="" width={96} height={72} unoptimized className="h-14 w-20 rounded-sm object-cover" /> : <span className="block h-14 w-20 rounded-sm bg-sand-200" />}
                  </td>
                  <td className="px-4 py-2">
                    <Link href={`/${lang}/admin/equipment/${r.id}`} className="font-semibold underline-offset-4 hover:underline">{lang === "ar" ? r.title_ar : r.title_en}</Link>
                    <p className="text-xs text-ink-muted">{e.kinds[r.kind]}{r.is_featured ? ` · ★` : ""}{r.branch ? ` · ${r.branch}` : ""}</p>
                  </td>
                  <td className="px-4 py-2" dir="ltr">{r.model}</td>
                  <td className="px-4 py-2">{e.conditions[r.condition]}</td>
                  <td className="num px-4 py-2 whitespace-nowrap">{r.price ? formatMoney(r.price, lang) : "—"}{r.price_visibility === "on_request" && <span className="block text-xs text-ink-muted">{e.onRequest}</span>}</td>
                  <td className="px-4 py-2 font-semibold" style={{ color: STATUS_COLOR[r.status] }}>{e.statuses[r.status]}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
