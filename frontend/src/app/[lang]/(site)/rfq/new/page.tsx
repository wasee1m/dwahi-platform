import { redirect } from "next/navigation";
import { Suspense } from "react";

// /rfq/new?type= (06 C-07) يحوّل لنموذج الطلب داخل الحساب، فيمر بالتحقق من الدخول في proxy.ts
type Props = PageProps<"/[lang]/rfq/new">;

export default function RfqNewRedirect({ params, searchParams }: Props) {
  return (
    <Suspense>
      <Go params={params} searchParams={searchParams} />
    </Suspense>
  );
}

async function Go({ params, searchParams }: Props) {
  const [{ lang }, sp] = await Promise.all([params, searchParams]);
  const qs = new URLSearchParams(Object.entries(sp).filter(([, v]) => typeof v === "string") as [string, string][]).toString();
  redirect(`/${lang}/account/quotes/new${qs ? `?${qs}` : ""}`);
  return null;
}
