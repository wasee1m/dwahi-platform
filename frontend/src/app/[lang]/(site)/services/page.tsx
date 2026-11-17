import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, PageHeader } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { listBranches } from "@/lib/api";
import { formatPhone, telLink, whatsappLink } from "@/lib/format";
import { safe } from "@/lib/safe";
import { alternates } from "@/lib/seo";

// W-06 خدمات الصيانة (FR-CMS-01): في الورشة فقط. طلب الصيانة من الموقع يأتي مع S7.
export async function generateMetadata({ params }: PageProps<"/[lang]/services">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.services.title, description: dict.services.intro, alternates: alternates(lang, "/services") };
}

export default async function ServicesPage({ params }: PageProps<"/[lang]/services">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  const t = dict.services;

  return (
    <>
      <PageHeader title={t.title}>{t.intro}</PageHeader>
      <Container className="py-10">
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {t.list.map((s) => (
            <li key={s.title} className="rounded-md border border-sand-300 bg-sand-0 p-6">
              <h2 className="text-lg">{s.title}</h2>
              <p className="mt-2 text-sm text-ink-muted">{s.text}</p>
            </li>
          ))}
        </ul>

        <section className="mt-14">
          <h2 className="text-2xl">{t.howTitle}</h2>
          <ol className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            {t.steps.map((step, i) => (
              <li key={step} className="rounded-md bg-sand-0 p-4 ring-1 ring-sand-300">
                <span className="num flex size-8 items-center justify-center rounded-full bg-sun font-bold text-on-sun">{i + 1}</span>
                <p className="mt-3 text-sm font-semibold">{step}</p>
              </li>
            ))}
          </ol>
        </section>

        <Suspense>
          <ServiceContact lang={lang} dict={dict} />
        </Suspense>
      </Container>
    </>
  );
}

async function ServiceContact({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const branch = (await safe(listBranches(lang), []))[0];
  if (!branch) return null;
  const wa = branch.whatsapp_service ?? branch.whatsapp_sales;
  return (
    <section className="mt-14 rounded-md bg-night p-8 text-on-night">
      <h2 className="text-2xl">{dict.services.cta}</h2>
      <p className="mt-2 opacity-85">{dict.services.ctaText}</p>
      <p className="mt-2 text-sm opacity-85">{branch.address}</p>
      <div className="mt-5 flex flex-wrap gap-3">
        {wa && (
          <a href={whatsappLink(wa)} rel="noopener" target="_blank" className="rounded-sm bg-sun px-5 py-3 font-bold text-on-sun hover:bg-sun-deep">
            {dict.branches.whatsappService}
          </a>
        )}
        {branch.phone && (
          <a href={telLink(branch.phone)} className="num rounded-sm border border-on-night/40 px-5 py-3 font-bold">{formatPhone(branch.phone)}</a>
        )}
      </div>
    </section>
  );
}
