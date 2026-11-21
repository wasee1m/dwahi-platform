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

// A-04 القطع والمخزون (FR-PRT-04، US-37): بحث بالرقم المطبّع أو الاسم، مع المتوفر والمحجوز
type Row = { id: string; brand: string; part_number: string; name_ar: string; name_en: string; price: Money; is_active: boolean; on_hand: string; reserved: string };
type Props = PageProps<"/[lang]/admin/parts">;

export default async function PartsAdminPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const p = t.admin.parts;
  const link = "rounded-sm border border-ink px-4 py-2 font-semibold";
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{p.title}</h1>
        <div className="flex flex-wrap gap-2">
          <Link href={`/${lang}/admin/parts/receive`} className={link}>{p.receive}</Link>
          <Link href={`/${lang}/admin/parts/import`} className={link}>{p.import}</Link>
          <Link href={`/${lang}/admin/parts/new`} className="rounded-sm bg-sun px-5 py-2 font-bold text-on-sun hover:bg-sun-deep">{p.new}</Link>
        </div>
      </div>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("parts.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const q = typeof sp.q === "string" ? sp.q : "";
  const cursor = typeof sp.cursor === "string" ? sp.cursor : "";
  const qs = new URLSearchParams({ ...(q ? { q } : {}), ...(cursor ? { cursor } : {}) }).toString();
  const res = await authed<Row[]>("admin", lang, `/admin/parts${qs ? `?${qs}` : ""}`);
  const rows = res.ok ? res.data : [];
  const p = t.admin.parts;
  const c = t.admin.common;

  return (
    <>
      <form method="get" className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <label className="text-sm"><span className="mb-1 block font-semibold">{c.search}</span>
          <input name="q" defaultValue={q} placeholder={p.searchHint} className="w-72 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        </label>
        <button className="rounded-sm bg-night px-5 py-2 font-bold text-on-night">{c.search}</button>
      </form>

      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{c.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[800px] text-sm">
            <thead className="bg-sand-200">
              <tr>{[p.cols.number, p.cols.name, p.cols.brand, p.cols.price, p.cols.onHand, p.cols.active].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {rows.map((r) => (
                <tr key={r.id} className="hover:bg-sand-100">
                  <td className="num px-4 py-2" dir="ltr"><Link href={`/${lang}/admin/parts/${r.id}`} className="font-semibold underline-offset-4 hover:underline">{r.part_number}</Link></td>
                  <td className="px-4 py-2">{lang === "ar" ? r.name_ar : r.name_en}</td>
                  <td className="px-4 py-2" dir="ltr">{r.brand}</td>
                  <td className="num px-4 py-2 whitespace-nowrap">{formatMoney(r.price, lang)}</td>
                  <td className="num px-4 py-2">{r.on_hand}{Number(r.reserved) > 0 && <span className="text-xs text-ink-muted"> ({p.reserved} {r.reserved})</span>}</td>
                  <td className="px-4 py-2">{r.is_active ? c.yes : c.no}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
