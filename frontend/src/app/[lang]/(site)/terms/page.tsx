import { CmsPage, cmsMetadata } from "@/components/CmsPage";

export async function generateMetadata({ params }: PageProps<"/[lang]/terms">) {
  return cmsMetadata(params, "terms");
}

export default function Page({ params }: PageProps<"/[lang]/terms">) {
  return <CmsPage params={params} slug="terms" />;
}
