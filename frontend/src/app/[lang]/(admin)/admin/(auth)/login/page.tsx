import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { AuthShell } from "../AuthShell";
import { StaffLoginFlow } from "./StaffLoginFlow";

// A-21 دخول الموظفين (FR-AUTH-03، US-39)
export default async function StaffLoginPage({ params, searchParams }: PageProps<"/[lang]/admin/login">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <AuthShell title={t.admin.loginTitle}>
      <Suspense fallback={<Skeleton className="h-56" />}>
        <Inner lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </AuthShell>
  );
}

async function Inner({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: PageProps<"/[lang]/admin/login">["searchParams"] }) {
  const sp = await searchParams;
  const notice = sp.password_set ? t.admin.passwordSet : sp.expired ? t.auth.sessionExpired : undefined;
  return <StaffLoginFlow lang={lang} t={t.admin} auth={t.auth} next={typeof sp.next === "string" ? sp.next : undefined} notice={notice} />;
}
