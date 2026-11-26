import { notFound } from "next/navigation";
import { CmsPage, cmsMetadata } from "@/components/CmsPage";
import { OwnerCard } from "@/components/OwnerCard";
import { hasLocale } from "@/i18n/config";

export async function generateMetadata({ params }: PageProps<"/[lang]/about">) {
  return cmsMetadata(params, "about");
}

export default async function Page({ params }: PageProps<"/[lang]/about">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  return (
    <CmsPage params={params} slug="about">
      <OwnerCard lang={lang} variant="about" />
    </CmsPage>
  );
}
