import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { partOptions } from "../options";
import { AdjustForm, PartForm, type Part } from "../PartForms";

// A-04 تعديل قطعة: البيانات، والمخزون لكل مخزن، والتسوية بسبب، ودفتر الحركات (US-37)
type Props = PageProps<"/[lang]/admin/parts/[id]">;

// المعرّف غير معروف وقت البناء، فكل ما يقرأ params داخل Suspense
export default function EditPartPage({ params, searchParams }: Props) {
  return (
    <div className="max-w-5xl space-y-6">
      <Suspense fallback={<Skeleton className="h-[700px]" />}>
        <Editor params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Editor({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang, id }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("parts.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const [res, { brands, models, locations }] = await Promise.all([authed<Part>("admin", lang, `/admin/parts/${encodeURIComponent(id)}`), partOptions(lang)]);
  if (!res.ok) notFound();
  const part = res.data;
  const p = t.admin.parts;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <Link href={`/${lang}/admin/parts`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div>
        <h1 className="num text-3xl" dir="ltr">{part.part_number}</h1>
        <p className="text-ink-muted">{part.brand} · {lang === "ar" ? part.name_ar : part.name_en}</p>
      </div>
      {sp.created && <Notice tone="success">{t.admin.common.saved}</Notice>}

      <section className="space-y-4 rounded-md border border-sand-300 bg-sand-0 p-6">
        <h2 className="text-xl">{p.stock}</h2>
        {part.stock.length === 0 ? <p className="text-ink-muted">{t.admin.common.none}</p> : (
          <ul className="flex flex-wrap gap-3">
            {part.stock.map((s) => (
              <li key={s.location_id} className="rounded-sm bg-sand-100 px-4 py-2 ring-1 ring-sand-300">
                <span className="font-semibold">{s.location}</span>: <span className="num text-lg font-bold">{s.on_hand}</span>
                {Number(s.reserved) > 0 && <span className="text-sm text-ink-muted"> ({p.reserved} <span className="num">{s.reserved}</span>)</span>}
              </li>
            ))}
          </ul>
        )}
        <AdjustForm lang={lang} t={p} c={t.admin.common} partId={part.id} locations={locations} />
        <details>
          <summary className="cursor-pointer font-semibold">{p.movements} ({part.movements.length})</summary>
          <table className="mt-3 w-full text-sm">
            <tbody className="divide-y divide-sand-300">
              {part.movements.map((m, i) => (
                <tr key={i}>
                  <td className="py-2 whitespace-nowrap">{when.format(new Date(m.at))}</td>
                  <td className="py-2">{p.reasons[m.reason] ?? m.reason}</td>
                  <td className={`num py-2 font-bold ${m.qty_delta.startsWith("-") ? "text-danger" : "text-success"}`} dir="ltr">{m.qty_delta.startsWith("-") ? m.qty_delta : `+${m.qty_delta}`}</td>
                  <td className="py-2">{m.location}</td>
                  <td className="py-2 text-ink-muted">{m.by}{m.note ? ` · ${m.note}` : ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      </section>

      <PartForm lang={lang} t={p} c={t.admin.common} part={part} brands={brands} models={models} />
    </>
  );
}
