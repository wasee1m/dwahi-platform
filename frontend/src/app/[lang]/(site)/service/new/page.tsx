import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import type { ServiceMeta } from "@/lib/service-types";
import { ServiceWizard } from "./ServiceWizard";

// C-15 طلب صيانة جديد (FR-SRV-01، US-17): معالج من 4 خطوات، ورسوم الفحص تُعرض ويُقر بها قبل التأكيد (BR-11)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/service/new">;
type Machine = { id: string; name: string; serial_number: string | null; account: { id: string } | null };

export default async function NewServicePage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <Container className="py-10">
      <h1 className="text-3xl">{t.service.newTitle}</h1>
      <Suspense fallback={<Skeleton className="mt-6 h-96" />}>
        <Body lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}

async function Body({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("customer", lang);
  if (me.needs_onboarding) redirect(`/${lang}/onboarding`);
  const sp = await searchParams;
  const account = await selectedAccount(me);
  const [meta, fleet] = await Promise.all([
    authed<ServiceMeta>("customer", lang, "/me/service-requests/meta"),
    authed<Machine[]>("customer", lang, "/me/fleet"),
  ]);
  if (!meta.ok) return <p role="alert" className="mt-6 text-danger">{meta.error.message}</p>;
  const machines = (fleet.ok ? fleet.data : []).filter((m) => (m.account?.id ?? "personal") === account.value);
  const company = account.organizationId ? me.organizations.find((o) => o.id === account.organizationId)?.name ?? null : null;

  return (
    <ServiceWizard lang={lang} t={t.service} components={t.service.components} meta={meta.data} machines={machines}
      organizationId={account.organizationId} accountName={company ?? t.account.personal}
      preselected={typeof sp.equipment === "string" ? sp.equipment : null} />
  );
}
