import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { stockLocations } from "../options";
import { ReceiveForm } from "../PartForms";

// A-04 استلام شحنة مورد: حركة "استلام" لكل صنف في معاملة واحدة (US-37)
export default async function ReceivePage({ params }: PageProps<"/[lang]/admin/parts/receive">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-4xl space-y-6">
      <Link href={`/${lang}/admin/parts`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <h1 className="text-3xl">{t.admin.parts.receive}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Receive lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Receive({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("parts.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  return <ReceiveForm lang={lang} t={t.admin.parts} locations={await stockLocations(lang)} />;
}
