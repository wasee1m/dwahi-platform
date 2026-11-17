import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { CardGridSkeleton, Container, PageHeader } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { alternates } from "@/lib/seo";
import { Catalog } from "./Catalog";

export async function generateMetadata({ params }: PageProps<"/[lang]/equipment">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return { title: dict.equipment.title, alternates: alternates(lang, "/equipment") };
}

export default async function EquipmentPage({ params, searchParams }: PageProps<"/[lang]/equipment">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);

  return (
    <>
      <PageHeader title={dict.equipment.title} />
      <Container className="py-8">
        <Suspense fallback={<CardGridSkeleton />}>
          <CatalogFromQuery lang={lang} dict={dict} searchParams={searchParams} />
        </Suspense>
      </Container>
    </>
  );
}

// الفلاتر من الرابط بيانات وقت الطلب، فتُقرأ داخل Suspense (Cache Components)
async function CatalogFromQuery({ searchParams, ...rest }: {
  lang: Parameters<typeof Catalog>[0]["lang"]; dict: Parameters<typeof Catalog>[0]["dict"];
  searchParams: PageProps<"/[lang]/equipment">["searchParams"];
}) {
  return <Catalog {...rest} searchParams={await searchParams} basePath={`/${rest.lang}/equipment`} />;
}
