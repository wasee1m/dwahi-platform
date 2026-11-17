import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";

// /admin: المؤشرات (A-01) تأتي في S10. الآن: الأقسام المتاحة للدور.
export default async function AdminHome({ params }: PageProps<"/[lang]/admin">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-3xl">
      <h1 className="text-3xl">{t.admin.panel}</h1>
      <p className="mt-2 text-ink-muted">{t.admin.home.intro}</p>
      <Suspense fallback={<Skeleton className="mt-6 h-32" />}>
        <Sections lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Sections({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  const cards = [
    { href: "/admin/users", label: t.admin.nav.users, ability: "staff.manage" },
    { href: "/admin/audit-log", label: t.admin.nav.audit, ability: "audit.view" },
  ].filter((c) => me.permissions.includes(c.ability));
  return (
    <div className="mt-6 grid gap-3 sm:grid-cols-2">
      {cards.map((c) => (
        <Link key={c.href} href={`/${lang}${c.href}`} className="rounded-md border border-sand-300 bg-sand-0 p-6 text-lg font-bold hover:border-sun">{c.label}</Link>
      ))}
    </div>
  );
}
