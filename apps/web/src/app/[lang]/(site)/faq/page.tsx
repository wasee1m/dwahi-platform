import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, PageHeader, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getDictionary, type Dictionary } from "@/i18n/get-dictionary";
import { listFaqs } from "@/lib/api";
import { alternates } from "@/lib/seo";

// W-10 الأسئلة الشائعة (FR-CMS-03): أقسام وأكورديون دون JavaScript
export async function generateMetadata({ params }: PageProps<"/[lang]/faq">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.faq.title, alternates: alternates(lang, "/faq") };
}

export default async function FaqPage({ params }: PageProps<"/[lang]/faq">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  return (
    <>
      <PageHeader title={dict.faq.title} />
      <Container className="max-w-3xl py-10">
        <Suspense fallback={<Skeleton className="h-72" />}>
          <Faqs lang={lang} dict={dict} />
        </Suspense>
        <div className="mt-10 rounded-md bg-sand-0 p-6 ring-1 ring-sand-300">
          <p className="font-semibold">{dict.faq.notFound}</p>
          <Link href={`/${lang}/contact`} className="mt-3 inline-flex rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun">{dict.faq.askUs}</Link>
        </div>
      </Container>
    </>
  );
}

async function Faqs({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const faqs = await listFaqs(lang);
  const sectionLabel: Record<string, string> = { service: dict.nav.services, parts: dict.nav.parts, equipment: dict.nav.equipment };
  const sections = [...new Set(faqs.map((f) => f.section))];
  return (
    <div className="space-y-10">
      {sections.map((s) => (
        <section key={s}>
          <h2 className="text-xl">{sectionLabel[s] ?? s}</h2>
          <div className="mt-4 divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
            {faqs.filter((f) => f.section === s).map((f) => (
              <details key={f.id} className="group px-5 py-4">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold [&::-webkit-details-marker]:hidden">
                  {f.question}
                  <span className="text-sun-text transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <p className="mt-3 leading-relaxed text-ink-muted">{f.answer}</p>
              </details>
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
