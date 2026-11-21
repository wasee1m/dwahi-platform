import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { EquipmentForm } from "../EquipmentForms";
import { editorOptions } from "../options";

// A-02 معروض جديد: يُحفظ مسودة، ثم تُضاف الصور ويُنشر من صفحة التعديل (US-36)
export default async function NewEquipmentPage({ params }: PageProps<"/[lang]/admin/equipment/new">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-5xl space-y-6">
      <Link href={`/${lang}/admin/equipment`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <h1 className="text-3xl">{t.admin.equipment.new}</h1>
      <p className="text-ink-muted">{t.admin.equipment.saveFirst}</p>
      <Suspense fallback={<Skeleton className="h-[600px]" />}>
        <Editor lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Editor({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("catalog.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const { models, branches } = await editorOptions(lang);
  return <EquipmentForm lang={lang} t={t.admin.equipment} c={t.admin.common} models={models} branches={branches} />;
}
