import { notFound } from "next/navigation";
import { Suspense } from "react";
import { AuthCard, Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { NewPasswordForm } from "../PasswordRecovery";

// الرابط من بريد الاستعادة (SetPasswordLink)
export const metadata = { robots: { index: false }, referrer: "no-referrer" as const };
type Props = PageProps<"/[lang]/login/reset">;

export default async function ResetPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <AuthCard title={t.recovery.resetTitle}>
      <Suspense fallback={<Skeleton className="h-48" />}>
        <Inner lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </AuthCard>
  );
}

async function Inner({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  if (typeof sp.token !== "string" || typeof sp.email !== "string") return <Notice tone="danger">{t.recovery.invalidLink}</Notice>;
  return <NewPasswordForm lang={lang} t={t.recovery} profile={t.profile} email={sp.email} token={sp.token} />;
}
