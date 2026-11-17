import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { AuthCard } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { pendingOtp } from "../actions";
import { CodeForm } from "../LoginForms";

// W-15 التحقق OTP: 6 خانات، "تذكرني"، عداد إعادة الإرسال، رسائل القفل، "تغيير الرقم"
export const metadata = { robots: { index: false } };

export default async function VerifyPage({ params }: PageProps<"/[lang]/login/verify">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).auth;
  return (
    <Suspense fallback={<AuthCard title={t.verifyTitle}><Skeleton className="h-48" /></AuthCard>}>
      <Verify lang={lang} t={t} />
    </Suspense>
  );
}

async function Verify({ lang, t }: { lang: Locale; t: AppDictionary["auth"] }) {
  const otp = await pendingOtp();
  if (!otp) redirect(`/${lang}/login`);
  // عرض الرقم جزئياً فقط
  const masked = otp.phone.replace(/\s/g, "").replace(/^(.*?)(\d{2})$/, (_, a: string, b: string) => `${"•".repeat(Math.max(0, a.length - 2))}${a.slice(-2)}${b}`);

  return (
    <AuthCard title={t.verifyTitle} intro={<>{t.verifyIntro} <span className="num">{masked}</span></>}>
      <CodeForm lang={lang} t={t} sentAt={otp.sentAt} debugCode={otp.debug} />
    </AuthCard>
  );
}
