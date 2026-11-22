import Link from "next/link";
import { notFound } from "next/navigation";
import { AuthCard } from "@/components/forms";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { ForgotPasswordForm } from "../PasswordRecovery";

// استعادة كلمة المرور بالبريد (FR-AUTH-04)
export const metadata = { robots: { index: false } };

export default async function ForgotPage({ params }: PageProps<"/[lang]/login/forgot">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <AuthCard title={t.recovery.forgotTitle} intro={t.recovery.forgotIntro}>
      <ForgotPasswordForm lang={lang} t={t.recovery} emailLabel={t.auth.email} />
      <Link href={`/${lang}/login`} className="mt-5 block text-center text-sm font-semibold text-sun-text hover:underline">{t.auth.phoneInstead}</Link>
    </AuthCard>
  );
}
