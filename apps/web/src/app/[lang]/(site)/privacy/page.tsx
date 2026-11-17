import { CmsPage, cmsMetadata } from "@/components/CmsPage";

export async function generateMetadata({ params }: PageProps<"/[lang]/privacy">) {
  return cmsMetadata(params, "privacy");
}

export default function Page({ params }: PageProps<"/[lang]/privacy">) {
  return <CmsPage params={params} slug="privacy" />;
}
