import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { OnBehalfForm } from "../RfqForms";

// A-05 طلب نيابة عن عميل (01 §3.2، SM-1 1.1): لطلب ورد بالهاتف أو واتساب أو بالحضور
export default async function NewRfqPage({ params }: PageProps<"/[lang]/admin/rfqs/new">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-4xl space-y-6">
      <Link href={`/${lang}/admin/rfqs`} className="text-sm font-semibold text-ink-muted">← {t.admin.rfqs.title}</Link>
      <h1 className="text-3xl">{t.admin.rfqs.new}</h1>
      <Suspense fallback={<Skeleton className="h-64" />}>
        <Form lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Form({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("rfq.create_on_behalf")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  return <OnBehalfForm lang={lang} t={t.admin.rfqs} />;
}
