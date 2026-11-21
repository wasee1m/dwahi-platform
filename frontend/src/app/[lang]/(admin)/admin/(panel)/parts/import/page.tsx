import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { ImportFlow } from "../PartForms";

// A-04 استيراد القطع من Excel: معاينة ثم تطبيق الكل أو لا شيء (US-37)
export default async function ImportPage({ params }: PageProps<"/[lang]/admin/parts/import">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-5xl space-y-6">
      <Link href={`/${lang}/admin/parts`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.admin.parts.import}</h1>
        <a href={`/${lang}/admin/parts/import/template`} download className="rounded-sm border border-ink px-4 py-2 font-semibold">{t.admin.parts.template} ↓</a>
      </div>
      <p className="text-ink-muted">{t.admin.parts.importIntro}</p>
      <Suspense fallback={<Skeleton className="h-64" />}>
        <Flow lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Flow({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("parts.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  return <ImportFlow lang={lang} t={t.admin.parts} c={t.admin.common} />;
}
