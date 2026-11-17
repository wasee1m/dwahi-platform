import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { AuthCard } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { EmailForm } from "../LoginForms";

// الدخول بالبريد وكلمة المرور إن أنشأها العميل (FR-AUTH-02)
export const metadata = { robots: { index: false } };

export default async function EmailLoginPage({ params, searchParams }: PageProps<"/[lang]/login/email">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).auth;
  return (
    <AuthCard title={t.loginTitle}>
      <Suspense fallback={<Skeleton className="h-48" />}>
        <Inner lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
      <Link href={`/${lang}/login`} className="mt-5 block text-center text-sm font-semibold text-sun-text hover:underline">{t.phoneInstead}</Link>
    </AuthCard>
  );
}

async function Inner({ lang, t, searchParams }: { lang: string; t: Awaited<ReturnType<typeof getAppDictionary>>["auth"]; searchParams: PageProps<"/[lang]/login/email">["searchParams"] }) {
  const sp = await searchParams;
  return <EmailForm lang={lang} t={t} next={typeof sp.next === "string" ? sp.next : undefined} />;
}
