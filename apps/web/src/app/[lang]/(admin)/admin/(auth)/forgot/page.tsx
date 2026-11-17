import { notFound } from "next/navigation";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { AuthShell } from "../AuthShell";
import { ForgotForm } from "../PasswordForms";

// "نسيت كلمة المرور" للموظفين (A-21، FR-AUTH-04)
export default async function ForgotPage({ params }: PageProps<"/[lang]/admin/forgot">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <AuthShell title={t.admin.forgotTitle} intro={t.admin.forgotIntro}>
      <ForgotForm lang={lang} t={t.admin} auth={t.auth} />
    </AuthShell>
  );
}
