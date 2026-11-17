import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getContentPage } from "@/lib/api";
import { alternates } from "@/lib/seo";
import { Markdown } from "./Markdown";
import { Container, Skeleton } from "./ui";

// صفحة محتوى من لوحة الإدارة (FR-CMS-01، FR-CMS-03): من نحن والصفحات القانونية (W-07، W-11)
export async function cmsMetadata(params: Promise<{ lang: string }>, slug: string): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const page = await getContentPage(lang, slug);
  if (!page) return {};
  return {
    title: page.seo_title ?? page.title,
    description: page.seo_description ?? undefined,
    alternates: alternates(lang, `/${slug}`),
  };
}

export async function CmsPage({ params, slug }: { params: Promise<{ lang: string }>; slug: string }) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  return (
    <Suspense fallback={<Container className="py-10"><Skeleton className="h-96" /></Container>}>
      <Body lang={lang} slug={slug} />
    </Suspense>
  );
}

async function Body({ lang, slug }: { lang: Locale; slug: string }) {
  const page = await getContentPage(lang, slug);
  if (!page) notFound();
  const updated = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "long", timeZone: "Asia/Muscat" }).format(new Date(page.updated_at));

  return (
    <>
      <div className="border-b border-sand-300 bg-sand-0">
        <Container className="py-10">
          <h1 className="text-3xl md:text-4xl">{page.title}</h1>
          <p className="mt-2 text-sm text-ink-muted"><time dateTime={page.updated_at}>{updated}</time></p>
        </Container>
      </div>
      <Container className="max-w-3xl py-10">
        <Markdown source={page.body} />
      </Container>
    </>
  );
}
