import { CmsPage, cmsMetadata } from "@/components/CmsPage";

export async function generateMetadata({ params }: PageProps<"/[lang]/warranty">) {
  return cmsMetadata(params, "warranty");
}

export default function Page({ params }: PageProps<"/[lang]/warranty">) {
  return <CmsPage params={params} slug="warranty" />;
}
