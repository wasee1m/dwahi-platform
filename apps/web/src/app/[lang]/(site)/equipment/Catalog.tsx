import Link from "next/link";
import { EquipmentCard } from "@/components/EquipmentCard";
import type { Locale } from "@/i18n/config";
import type { Dictionary } from "@/i18n/get-dictionary";
import { equipmentFacets, listEquipment, type EquipmentFilters } from "@/lib/api";

// W-02 كتالوج المعدات (FR-CAT-01، FR-CAT-02، US-01). الفلاتر في الرابط، والنموذج يعمل دون JavaScript.
const KINDS = ["machine", "component", "scrap"] as const;
const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) || undefined;

export async function Catalog({
  lang, dict, searchParams, basePath, fixedCategory,
}: {
  lang: Locale; dict: Dictionary; searchParams: Record<string, string | string[] | undefined>;
  basePath: string; fixedCategory?: string;
}) {
  const t = dict.equipment;
  const filters: EquipmentFilters = {
    kind: one(searchParams.kind),
    category: fixedCategory ?? one(searchParams.category),
    brand: one(searchParams.brand),
    condition: one(searchParams.condition),
    sort: one(searchParams.sort),
    cursor: one(searchParams.cursor),
  };
  const [page, facets] = await Promise.all([listEquipment(lang, filters), equipmentFacets(lang, filters)]);

  const href = (patch: Partial<EquipmentFilters>) => {
    const next = { ...filters, cursor: undefined, ...patch };
    if (fixedCategory) delete next.category;
    const qs = new URLSearchParams(Object.entries(next).filter(([, v]) => v) as [string, string][]).toString();
    return `${basePath}${qs ? `?${qs}` : ""}`;
  };
  const kindCount = (k: string) => facets?.kinds.find((x) => x.value === k)?.count ?? 0;
  const total = facets?.kinds.reduce((s, k) => s + k.count, 0) ?? 0;

  return (
    <>
      <nav aria-label={t.filters} className="flex flex-wrap gap-2 border-b border-sand-300 pb-4">
        {[undefined, ...KINDS].map((kind) => {
          const active = filters.kind === kind;
          return (
            <Link
              key={kind ?? "all"} href={href({ kind })} aria-current={active ? "page" : undefined}
              className={`rounded-sm px-4 py-2 text-sm font-semibold ${active ? "bg-ink text-sand-0" : "bg-sand-0 ring-1 ring-sand-300 hover:bg-sand-200"}`}
            >
              {t.kinds[kind ?? "all"]} <span className="num opacity-70">{kind ? kindCount(kind) : total}</span>
            </Link>
          );
        })}
      </nav>

      <div className="mt-6 grid gap-8 lg:grid-cols-[260px_1fr]">
        <form method="get" action={basePath} className="space-y-4 self-start rounded-md border border-sand-300 bg-sand-0 p-4">
          <h2 className="text-base">{t.filters}</h2>
          {filters.kind && <input type="hidden" name="kind" value={filters.kind} />}
          {!fixedCategory && (
            <Select name="category" label={t.category} value={filters.category} any={t.any}
              options={(facets?.categories ?? []).map((c) => ({ value: c.slug, label: `${c.name} (${c.count})` }))} />
          )}
          <Select name="brand" label={t.brand} value={filters.brand} any={t.any}
            options={(facets?.brands ?? []).map((b) => ({ value: b.slug, label: `${b.name} (${b.count})` }))} />
          <Select name="condition" label={t.condition} value={filters.condition} any={t.any}
            options={(facets?.conditions ?? []).filter((c) => c.count > 0).map((c) => ({ value: c.value, label: `${t.conditions[c.value]} (${c.count})` }))} />
          <Select name="sort" label={t.sort} value={filters.sort} options={[
            { value: "newest", label: t.sortNewest },
            { value: "price_asc", label: t.sortPriceAsc },
            { value: "price_desc", label: t.sortPriceDesc },
          ]} />
          <div className="flex gap-2">
            <button className="flex-1 rounded-sm bg-sun px-4 py-2 font-bold text-on-sun hover:bg-sun-deep">{t.apply}</button>
            <Link href={basePath} className="rounded-sm px-3 py-2 text-sm font-semibold text-ink-muted hover:bg-sand-200">{t.reset}</Link>
          </div>
        </form>

        <section aria-live="polite">
          <p className="mb-4 text-sm text-ink-muted"><span className="num">{page.total ?? page.data.length}</span> {t.results}</p>
          {page.data.length === 0 ? (
            <div className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-10 text-center">
              <p className="font-semibold">{t.noResults}</p>
              <p className="mt-2 text-sm text-ink-muted">{t.noResultsAction}</p>
              <div className="mt-4 flex justify-center gap-3">
                <Link href={basePath} className="rounded-sm border border-ink px-4 py-2 font-semibold">{t.reset}</Link>
                <Link href={`/${lang}/contact?topic=equipment`} className="rounded-sm bg-sun px-4 py-2 font-bold text-on-sun">{dict.nav.contact}</Link>
              </div>
            </div>
          ) : (
            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {page.data.map((item) => <EquipmentCard key={item.id} item={item} lang={lang} dict={dict} />)}
            </div>
          )}
          <div className="mt-8 flex justify-between">
            {filters.cursor ? <Link href={href({})} className="font-semibold text-sun-text hover:underline">{t.firstPage}</Link> : <span />}
            {page.next_cursor && (
              <Link href={href({ cursor: page.next_cursor })} className="rounded-sm border border-ink px-5 py-2 font-semibold hover:bg-sand-200">
                {t.nextPage}
              </Link>
            )}
          </div>
        </section>
      </div>
    </>
  );
}

function Select({ name, label, value, options, any }: {
  name: string; label: string; value?: string; any?: string; options: { value: string; label: string }[];
}) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-semibold">{label}</span>
      <select name={name} defaultValue={value ?? ""} className="w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2">
        {any !== undefined && <option value="">{any}</option>}
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  );
}
