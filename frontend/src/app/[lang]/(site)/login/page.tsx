import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { AuthCard, Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { PhoneForm } from "./LoginForms";

// W-14 الدخول / التسجيل (FR-AUTH-01، FR-AUTH-02، US-06، US-07)
export async function generateMetadata({ params }: PageProps<"/[lang]/login">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  return { title: (await getAppDictionary(lang)).auth.loginTitle, robots: { index: false } };
}

export default async function LoginPage({ params, searchParams }: PageProps<"/[lang]/login">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).auth;
  return (
    <AuthCard title={t.loginTitle} intro={t.loginIntro}>
      <Suspense fallback={<Skeleton className="h-40" />}>
        <Form lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </AuthCard>
  );
}

async function Form({ lang, t, searchParams }: { lang: string; t: Awaited<ReturnType<typeof getAppDictionary>>["auth"]; searchParams: PageProps<"/[lang]/login">["searchParams"] }) {
  const sp = await searchParams;
  const next = typeof sp.next === "string" ? sp.next : undefined;
  return (
    <div className="space-y-5">
      {sp.expired && <Notice>{t.sessionExpired}</Notice>}
      <PhoneForm lang={lang} t={t} next={next} />
      <p className="text-xs text-ink-muted">{t.termsNote}</p>
      <Link href={`/${lang}/login/email${next ? `?next=${encodeURIComponent(next)}` : ""}`} className="block text-center text-sm font-semibold text-sun-text hover:underline">
        {t.emailInstead}
      </Link>
    </div>
  );
}
