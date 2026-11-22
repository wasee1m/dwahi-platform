import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { Address, CatalogModel } from "@/lib/account-types";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { EquipmentForm } from "../FleetForms";

// C-05 إضافة معدة، من الكتالوج أو "موديل آخر" (US-10)
export const metadata = { robots: { index: false } };

export default async function NewEquipmentPage({ params }: PageProps<"/[lang]/account/fleet/new">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <Link href={`/${lang}/account/fleet`} className="text-sm font-semibold text-ink-muted">← {t.fleet.title}</Link>
      <h1 className="text-3xl">{t.fleet.addTitle}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Form lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Form({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const account = await selectedAccount(await currentUser("customer", lang));
  const [models, sites] = await Promise.all([
    authed<CatalogModel[]>("customer", lang, "/public/models"),
    authed<Address[]>("customer", lang, `/me/addresses?kind=site&account=${account.value}`),
  ]);
  if (!models.ok || !sites.ok) return <Notice tone="danger">{t.auth.genericError}</Notice>;
  return (
    <div className="rounded-md border border-sand-300 bg-sand-0 p-5">
      <EquipmentForm lang={lang} t={t.fleet} models={models.data} sites={sites.data} organizationId={account.organizationId} />
    </div>
  );
}
