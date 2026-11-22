import Link from "next/link";
import { AvailabilityBadge } from "@/components/Badges";
import { ButtonLink } from "@/components/ui";
import type { Locale } from "@/i18n/config";
import type { Dictionary } from "@/i18n/get-dictionary";
import type { CatalogModel, CompatibleParts, FleetItem } from "@/lib/account-types";
import { api } from "@/lib/auth/client";
import { accessToken } from "@/lib/auth/session";
import { formatMoney } from "@/lib/format";

// W-04 "حسب المعدة": ماركة ← موديل، أو من أسطولي للمسجلين (FR-PRT-02، US-04)
export async function ByEquipment({ lang, dict, model, equipment }: { lang: Locale; dict: Dictionary; model?: string; equipment?: string }) {
  const t = dict.parts.byEquipment;
  const token = await accessToken("customer");
  const [models, found, fleet] = await Promise.all([
    api<CatalogModel[]>("/public/models", { locale: lang }),
    model ? api<CompatibleParts>(`/parts/compatible?model_id=${encodeURIComponent(model)}`, { locale: lang }) : null,
    // الزائر ومن انتهت جلسته يتصفح دون "من أسطولي"
    token ? api<FleetItem[]>("/me/fleet", { locale: lang, token }) : null,
  ]);
  const brands = new Map<string, CatalogModel[]>();
  for (const m of models.ok ? models.data : []) brands.set(m.brand, [...(brands.get(m.brand) ?? []), m]);
  const mine = fleet?.ok ? fleet.data : [];
  const linked = mine.find((e) => e.id === equipment);
  const request = `/${lang}/account/quotes/new?type=parts${linked ? `&equipment=${linked.id}` : ""}`;

  return (
    <div className="space-y-5">
      <form method="get" className="flex max-w-2xl flex-wrap items-end gap-2">
        <label className="min-w-0 flex-1 text-sm">
          <span className="mb-1 block font-semibold">{t.model}</span>
          <select name="model" required defaultValue={model ?? ""} className="w-full rounded-sm border border-sand-300 bg-sand-0 px-3 py-3">
            <option value="" disabled>—</option>
            {[...brands].map(([brand, list]) => (
              <optgroup key={brand} label={brand}>
                {list.map((m) => <option key={m.id} value={m.id}>{m.brand} {m.model_name}</option>)}
              </optgroup>
            ))}
          </select>
        </label>
        <button className="rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep">{t.show}</button>
      </form>

      {mine.length > 0 && (
        <nav aria-label={t.fromFleet}>
          <p className="mb-2 text-sm font-semibold">{t.fromFleet}</p>
          <ul className="flex flex-wrap gap-2">
            {mine.map((e) => (
              <li key={e.id}>
                <Link
                  href={e.model_id ? `?model=${e.model_id}&equipment=${e.id}` : `?by=equipment&equipment=${e.id}`}
                  aria-current={e.id === equipment ? "true" : undefined}
                  className={`inline-block rounded-[999px] px-3 py-1 text-sm font-semibold ${e.id === equipment ? "bg-night text-on-night" : "bg-sand-200"}`}
                >
                  {e.name}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      )}

      {linked && !linked.model_id && !model && (
        <div className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8">
          <p className="font-semibold">{t.notLinked}</p>
          <ButtonLink href={request} className="mt-4">{t.requestFor}</ButtonLink>
        </div>
      )}

      {found && (!found.ok || found.data.groups.length === 0) && (
        <div className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8">
          <p className="font-semibold">{t.none}</p>
          <ButtonLink href={request} className="mt-4">{t.requestFor}</ButtonLink>
        </div>
      )}

      {found?.ok && found.data.groups.map((g) => (
        <section key={g.category ?? ""}>
          <h2 className="mb-2 text-lg">{g.category ?? t.other}</h2>
          <ul className="divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
            {g.parts.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                <span>
                  <Link href={`/${lang}/parts/${p.brand.slug}/${p.part_number_normalized}`} className="num font-semibold text-sun-text hover:underline">{p.part_number}</Link>
                  {" · "}{p.name} <span className="text-ink-muted">({p.brand.name})</span>
                </span>
                <span className="flex flex-wrap items-center gap-3">
                  <span className="num">{formatMoney(p.price, lang)}</span>
                  <AvailabilityBadge level={p.availability} dict={dict} />
                  {linked && (
                    <Link href={`${request}&part=${p.id}&label=${encodeURIComponent(`${p.brand.name} ${p.part_number} · ${p.name}`)}`} className="font-semibold underline">{t.request}</Link>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
