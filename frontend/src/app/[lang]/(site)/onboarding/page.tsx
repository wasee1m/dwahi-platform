import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { AuthCard } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { OnboardingForm } from "./OnboardingForm";

export const metadata = { robots: { index: false } };

export default async function OnboardingPage({ params }: PageProps<"/[lang]/onboarding">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).onboarding;
  return (
    <AuthCard title={t.title} intro={t.intro}>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Inner lang={lang} t={t} />
      </Suspense>
    </AuthCard>
  );
}

async function Inner({ lang, t }: { lang: Locale; t: AppDictionary["onboarding"] }) {
  const me = await currentUser("customer", lang);
  if (!me.needs_onboarding) redirect(`/${lang}/account`);
  return <OnboardingForm lang={lang} t={t} legal={{ terms: `/${lang}/terms`, privacy: `/${lang}/privacy` }} />;
}
