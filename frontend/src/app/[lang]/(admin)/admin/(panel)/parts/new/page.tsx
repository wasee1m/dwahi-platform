import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { partOptions } from "../options";
import { PartForm } from "../PartForms";

// A-04 قطعة جديدة
export default async function NewPartPage({ params }: PageProps<"/[lang]/admin/parts/new">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-5xl space-y-6">
      <Link href={`/${lang}/admin/parts`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <h1 className="text-3xl">{t.admin.parts.new}</h1>
      <Suspense fallback={<Skeleton className="h-[600px]" />}>
        <Editor lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Editor({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("parts.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const { brands, models } = await partOptions(lang);
  return <PartForm lang={lang} t={t.admin.parts} c={t.admin.common} brands={brands} models={models} />;
}
