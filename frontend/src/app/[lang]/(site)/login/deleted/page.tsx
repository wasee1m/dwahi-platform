import { notFound } from "next/navigation";
import { AuthCard } from "@/components/forms";
import { ButtonLink } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";

// بعد تأكيد حذف الحساب (US-11)
export const metadata = { robots: { index: false } };

export default async function DeletedPage({ params }: PageProps<"/[lang]/login/deleted">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).recovery;
  return (
    <AuthCard title={t.deletedTitle} intro={t.deletedText}>
      <ButtonLink href={`/${lang}`} className="w-full">{t.home}</ButtonLink>
    </AuthCard>
  );
}
