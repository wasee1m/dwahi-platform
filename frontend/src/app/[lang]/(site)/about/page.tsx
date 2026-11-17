import { CmsPage, cmsMetadata } from "@/components/CmsPage";

export async function generateMetadata({ params }: PageProps<"/[lang]/about">) {
  return cmsMetadata(params, "about");
}

export default function Page({ params }: PageProps<"/[lang]/about">) {
  return <CmsPage params={params} slug="about" />;
}
