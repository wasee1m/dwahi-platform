import { CmsPage, cmsMetadata } from "@/components/CmsPage";

export async function generateMetadata({ params }: PageProps<"/[lang]/returns">) {
  return cmsMetadata(params, "returns");
}

export default function Page({ params }: PageProps<"/[lang]/returns">) {
  return <CmsPage params={params} slug="returns" />;
}
