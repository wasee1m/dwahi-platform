import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { AvailabilityBadge } from "@/components/Badges";
import { ButtonLink, Container, PageHeader, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { searchParts } from "@/lib/api";
import { formatMoney } from "@/lib/format";
import { alternates } from "@/lib/seo";

// W-04 بحث القطع (FR-PRT-01، FR-PRT-05، US-03). البحث حسب المعدة ومن أسطولي يأتي مع الحسابات (S5).
type Props = PageProps<"/[lang]/parts">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.parts.title, alternates: alternates(lang, "/parts") };
}

export default async function PartsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);

  return (
    <>
      <PageHeader title={dict.parts.title}>{dict.parts.hint}</PageHeader>
      <Container className="py-8">
        <Suspense fallback={<Skeleton className="h-64" />}>
          <Results lang={lang} dict={dict} searchParams={searchParams} />
        </Suspense>
      </Container>
    </>
  );
}

async function Results({ lang, dict, searchParams }: { lang: Locale; dict: Dictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const q = (Array.isArray(sp.q) ? sp.q[0] : sp.q)?.trim() ?? "";
  const t = dict.parts;
  const results = q ? await searchParts(lang, q) : [];

  return (
    <>
      <form method="get" role="search" className="flex max-w-2xl gap-2">
        <label htmlFor="q" className="sr-only">{t.placeholder}</label>
        <input id="q" name="q" type="search" defaultValue={q} placeholder={t.placeholder} autoComplete="off"
          className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-0 px-4 py-3 text-lg focus:border-night" />
        <button className="rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep">{t.search}</button>
      </form>

      <div className="mt-8" aria-live="polite">
        {!q && <p className="text-ink-muted">{t.startTyping}</p>}

        {q && results.length === 0 && (
          <div className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8">
            <p className="font-semibold">{t.noResults}</p>
            <p className="mt-2 text-sm text-ink-muted">{t.requestPartText}</p>
            <ButtonLink href={`/${lang}/contact?topic=parts&ref=${encodeURIComponent(q)}`} className="mt-4">{t.requestPart}</ButtonLink>
          </div>
        )}

        {results.length > 0 && (
          <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
            <table className="w-full min-w-[640px] text-sm">
              <thead className="bg-sand-200 text-start">
                <tr>
                  <th scope="col" className="px-4 py-3 text-start">{t.partNumber}</th>
                  <th scope="col" className="px-4 py-3 text-start">{t.name}</th>
                  <th scope="col" className="px-4 py-3 text-start">{t.brand}</th>
                  <th scope="col" className="px-4 py-3 text-end">{t.price}</th>
                  <th scope="col" className="px-4 py-3 text-start">{t.availability}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-sand-300">
                {results.map((p) => (
                  <tr key={p.id} className="hover:bg-sand-100">
                    <td className="px-4 py-3">
                      <Link href={`/${lang}/parts/${p.brand.slug}/${p.part_number_normalized}`} className="num font-semibold text-sun-text hover:underline">
                        {p.part_number}
                      </Link>
                      {p.matched_alternative && (
                        <p className="mt-1 text-xs text-ink-muted">{t.altMatch}: <span className="num">{p.matched_alternative}</span></p>
                      )}
                    </td>
                    <td className="px-4 py-3">{p.name}</td>
                    <td className="px-4 py-3">{p.brand.name}</td>
                    <td className="px-4 py-3 text-end"><span className="num">{formatMoney(p.price, lang)}</span></td>
                    <td className="px-4 py-3"><AvailabilityBadge level={p.availability} dict={dict} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  );
}
