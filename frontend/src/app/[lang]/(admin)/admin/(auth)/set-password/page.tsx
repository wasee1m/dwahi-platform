import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { AuthShell } from "../AuthShell";
import { ResetForm } from "../PasswordForms";

// تعيين كلمة المرور من رابط البريد: تفعيل الموظف الجديد أو الاستعادة
export default async function SetPasswordPage({ params, searchParams }: PageProps<"/[lang]/admin/set-password">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <AuthShell title={t.admin.setPasswordTitle} intro={t.admin.setPasswordIntro}>
      <Suspense fallback={<Skeleton className="h-48" />}>
        <Inner lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </AuthShell>
  );
}

async function Inner({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: PageProps<"/[lang]/admin/set-password">["searchParams"] }) {
  const sp = await searchParams;
  const email = typeof sp.email === "string" ? sp.email : "";
  const token = typeof sp.token === "string" ? sp.token : "";
  if (!email || !token) return <Notice tone="danger">{t.admin.invalidLink}</Notice>;
  return <ResetForm lang={lang} t={t.admin} labels={t.profile} email={email} token={token} />;
}
