import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { selectedAccount } from "@/lib/auth/account";
import { logout } from "../login/actions";
import { AccountSwitcher } from "./AccountSwitcher";

// إطار بوابة العميل (06 §2): قائمة جانبية، وكل الصفحات تتطلب الدخول (proxy.ts يتحقق ويجدد الجلسة)
export default async function AccountLayout({ children, params }: LayoutProps<"/[lang]/account">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);

  return (
    <Container className="grid grid-cols-1 gap-8 py-10 lg:grid-cols-[230px_minmax(0,1fr)]">
      <Suspense fallback={<Skeleton className="h-56" />}>
        <Sidebar lang={lang} t={t} />
      </Suspense>
      <div className="min-w-0">{children}</div>
    </Container>
  );
}

async function Sidebar({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("customer", lang);
  if (me.needs_onboarding) redirect(`/${lang}/onboarding`);
  const account = await selectedAccount(me);
  const count = await authed<{ unread_count: number }>("customer", lang, "/me/notifications/count");
  const unread = count.ok ? count.data.unread_count : 0;
  const link = "flex items-center justify-between rounded-sm px-3 py-2 font-semibold hover:bg-sand-200";

  return (
    <aside className="self-start rounded-md border border-sand-300 bg-sand-0 p-4">
      <p className="font-display text-lg font-extrabold">{fill(t.account.hello, { name: me.full_name.split(" ")[0] })}</p>
      {me.organizations.length > 0 && <AccountSwitcher key={account.value} lang={lang} t={t.account} value={account.value} companies={me.organizations} />}
      <nav aria-label={t.account.title} className="mt-4">
        <ul className="space-y-1">
          <li><Link href={`/${lang}/account`} className={link}>{t.account.nav.dashboard}</Link></li>
          <li><Link href={`/${lang}/account/quotes`} className={link}>{t.account.nav.quotes}</Link></li>
          <li>
            <Link href={`/${lang}/account/notifications`} className={link}>
              {t.account.nav.notifications}
              {unread > 0 && <span className="num rounded-[999px] bg-sun px-2 text-xs font-bold text-on-sun" aria-label={String(unread)}>{unread}</span>}
            </Link>
          </li>
          <li><Link href={`/${lang}/account/fleet`} className={link}>{t.account.nav.fleet}</Link></li>
          <li><Link href={`/${lang}/account/company`} className={link}>{t.account.nav.company}</Link></li>
          <li><Link href={`/${lang}/account/addresses`} className={link}>{t.account.nav.addresses}</Link></li>
          <li><Link href={`/${lang}/account/profile`} className={link}>{t.account.nav.profile}</Link></li>
          <li><Link href={`/${lang}/account/settings`} className={link}>{t.account.nav.settings}</Link></li>
        </ul>
      </nav>
      <form action={logout} className="mt-4 border-t border-sand-300 pt-4">
        <input type="hidden" name="lang" value={lang} />
        <button className="w-full rounded-sm px-3 py-2 text-start text-sm font-semibold text-ink-muted hover:bg-sand-200">{t.account.logout}</button>
      </form>
    </aside>
  );
}
