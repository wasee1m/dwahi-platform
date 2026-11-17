import Link from "next/link";
import { Suspense } from "react";
import { EquipmentCard } from "@/components/EquipmentCard";
import { ButtonLink, CardGridSkeleton, Container } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { featuredEquipment, listBranches, listBrands } from "@/lib/api";
import { safe } from "@/lib/safe";
import { notFound } from "next/navigation";

// W-01 الرئيسية (FR-CMS-01). شهادات العملاء تظهر مع التقييمات (S9)، والبنرات حين تُضاف من A-14.
export default async function HomePage({ params }: PageProps<"/[lang]">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  const t = dict.home;

  return (
    <>
      <section className="border-b border-sand-300 bg-sand-0">
        <Container className="grid gap-10 py-14 md:grid-cols-[1.2fr_1fr] md:items-center md:py-20">
          <div>
            <h1 className="text-3xl leading-tight md:text-5xl">{t.heroTitle}</h1>
            <p className="mt-5 max-w-xl text-lg text-ink-muted">{t.heroText}</p>
            <form action={`/${lang}/parts`} className="mt-8 flex max-w-xl gap-2" role="search">
              <label htmlFor="hero-q" className="sr-only">{t.searchPlaceholder}</label>
              <input
                id="hero-q" name="q" type="search" placeholder={t.searchPlaceholder} autoComplete="off"
                className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-100 px-4 py-3 focus:border-night"
              />
              <button className="rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep">{t.searchButton}</button>
            </form>
          </div>
          <div className="grid grid-cols-3 gap-3 text-center">
            {[
              ["+30", t.yearsLabel],
              ["20", t.techniciansLabel],
              ["1", t.workshopLabel],
            ].map(([value, label]) => (
              <div key={label} className="rounded-md border border-sand-300 bg-sand-100 p-4">
                <p className="num font-display text-3xl font-extrabold text-sun-text">{value}</p>
                <p className="mt-1 text-xs text-ink-muted">{label}</p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      <Container className="py-12">
        <h2 className="text-2xl">{t.activities}</h2>
        <div className="mt-6 grid gap-4 md:grid-cols-3">
          {[
            { href: "/equipment", title: t.browseEquipment, text: t.browseEquipmentText },
            { href: "/parts", title: t.findPart, text: t.findPartText },
            { href: "/services", title: t.requestService, text: t.requestServiceText },
          ].map((a) => (
            <Link key={a.href} href={`/${lang}${a.href}`} className="group rounded-md border border-sand-300 bg-sand-0 p-6 hover:border-sun">
              <h3 className="text-lg">{a.title}</h3>
              <p className="mt-2 text-sm text-ink-muted">{a.text}</p>
              <span className="mt-4 inline-block text-sun-text group-hover:translate-x-0.5 rtl:rotate-180" aria-hidden="true">→</span>
            </Link>
          ))}
        </div>
      </Container>

      <Container className="py-6">
        <div className="flex items-end justify-between gap-4">
          <h2 className="text-2xl">{t.featured}</h2>
          <Link href={`/${lang}/equipment`} className="font-semibold text-sun-text hover:underline">{t.viewAll}</Link>
        </div>
        <div className="mt-6">
          <Suspense fallback={<CardGridSkeleton />}>
            <Featured lang={lang} dict={dict} />
          </Suspense>
        </div>
      </Container>

      <Suspense>
        <Brands lang={lang} dict={dict} />
      </Suspense>

      <Container className="py-12">
        <div className="grid gap-6 rounded-md bg-night p-8 text-on-night md:grid-cols-2 md:items-center">
          <div>
            <h2 className="text-2xl">{t.visitUs}</h2>
            <Suspense>
              <WorkshopAddress lang={lang} />
            </Suspense>
          </div>
          <div className="md:text-end">
            <h3 className="text-lg">{t.appTitle}</h3>
            <p className="mt-2 text-sm opacity-85">{t.appText}</p>
            <ButtonLink href={`/${lang}/branches`} className="mt-4">{dict.nav.branches}</ButtonLink>
          </div>
        </div>
      </Container>
    </>
  );
}

async function Featured({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const items = await featuredEquipment(lang);
  if (items.length === 0) return <p className="text-ink-muted">{dict.equipment.noResults}</p>;
  return (
    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((item) => <EquipmentCard key={item.id} item={item} lang={lang} dict={dict} />)}
    </div>
  );
}

async function Brands({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const brands = await safe(listBrands(lang), []);
  if (brands.length === 0) return null;
  return (
    <Container className="py-10">
      <h2 className="text-2xl">{dict.home.brands}</h2>
      <ul className="mt-6 flex flex-wrap gap-3">
        {brands.map((b) => (
          <li key={b.slug}>
            <Link href={`/${lang}/equipment?brand=${b.slug}`} className="block rounded-sm border border-sand-300 bg-sand-0 px-5 py-3 font-display font-extrabold tracking-wide hover:border-sun">
              {b.name}
            </Link>
          </li>
        ))}
      </ul>
    </Container>
  );
}

async function WorkshopAddress({ lang }: { lang: Locale }) {
  const branches = await safe(listBranches(lang), []);
  const main = branches[0];
  if (!main) return null;
  return <p className="mt-2 opacity-85">{main.address}</p>;
}
