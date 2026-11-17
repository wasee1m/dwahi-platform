import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { currentUser } from "@/lib/auth/client";
import { logout } from "../login/actions";

// إطار بوابة العميل (06 §2): قائمة جانبية، وكل الصفحات تتطلب الدخول (proxy.ts يتحقق ويجدد الجلسة)
export default async function AccountLayout({ children, params }: LayoutProps<"/[lang]/account">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);

  return (
    <Container className="grid gap-8 py-10 lg:grid-cols-[230px_1fr]">
      <Suspense fallback={<Skeleton className="h-56" />}>
        <Sidebar lang={lang} t={t} />
      </Suspense>
      <div>{children}</div>
    </Container>
  );
}

async function Sidebar({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("customer", lang);
  if (me.needs_onboarding) redirect(`/${lang}/onboarding`);
  const org = me.organizations[0];

  return (
    <aside className="self-start rounded-md border border-sand-300 bg-sand-0 p-4">
      <p className="font-display text-lg font-extrabold">{fill(t.account.hello, { name: me.full_name.split(" ")[0] })}</p>
      {org && <p className="mt-1 text-sm text-ink-muted">{org.name} · {org.role === "owner" ? t.account.owner : t.account.member}</p>}
      <nav aria-label={t.account.title} className="mt-4">
        <ul className="space-y-1">
          <li><Link href={`/${lang}/account`} className="block rounded-sm px-3 py-2 font-semibold hover:bg-sand-200">{t.account.nav.dashboard}</Link></li>
          <li><Link href={`/${lang}/account/profile`} className="block rounded-sm px-3 py-2 font-semibold hover:bg-sand-200">{t.account.nav.profile}</Link></li>
        </ul>
      </nav>
      <form action={logout} className="mt-4 border-t border-sand-300 pt-4">
        <input type="hidden" name="lang" value={lang} />
        <button className="w-full rounded-sm px-3 py-2 text-start text-sm font-semibold text-ink-muted hover:bg-sand-200">{t.account.logout}</button>
      </form>
    </aside>
  );
}
