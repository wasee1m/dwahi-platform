import { notFound } from "next/navigation";
import { Suspense } from "react";
import { CardGridSkeleton, Container, Skeleton } from "@/components/ui";
import { PageHeader } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { equipmentFacets } from "@/lib/api";
import { Catalog } from "../../Catalog";

// /equipment/c/{category} (W-02): رابط ثابت لكل فئة لمحركات البحث.
// المسار ديناميكي، فكل قراءة لـ params و searchParams داخل Suspense.
type Props = PageProps<"/[lang]/equipment/c/[category]">;

export default function CategoryPage({ params, searchParams }: Props) {
  return (
    <Suspense fallback={<><div className="border-b border-sand-300 bg-sand-0"><Container className="py-10"><Skeleton className="h-10 w-64" /></Container></div><Container className="py-8"><CardGridSkeleton /></Container></>}>
      <CategoryCatalog params={params} searchParams={searchParams} />
    </Suspense>
  );
}

async function CategoryCatalog({ params, searchParams }: Props) {
  const { lang, category } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  const facets = await equipmentFacets(lang, { category });
  const name = facets?.categories.find((c) => c.slug === category)?.name;
  if (!name) notFound();

  return (
    <>
      <PageHeader title={name} />
      <Container className="py-8">
        <Catalog lang={lang} dict={dict} searchParams={await searchParams} basePath={`/${lang}/equipment/c/${category}`} fixedCategory={category} />
      </Container>
    </>
  );
}
