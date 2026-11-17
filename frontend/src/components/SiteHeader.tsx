import Link from "next/link";
import { Suspense } from "react";
import type { Locale } from "@/i18n/config";
import type { Dictionary } from "@/i18n/get-dictionary";
import { LanguageSwitch } from "./LanguageSwitch";
import { Logo } from "./Logo";

// الترويسة (06 §4.1). السلة والحساب تظهران مع حسابات العملاء (S5، S6).
export function SiteHeader({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const nav = [
    { href: "/equipment", label: dict.nav.equipment },
    { href: "/parts", label: dict.nav.parts },
    { href: "/services", label: dict.nav.services },
    { href: "/about", label: dict.nav.about },
    { href: "/branches", label: dict.nav.branches },
    { href: "/contact", label: dict.nav.contact },
  ];
  const links = nav.map((item) => (
    <li key={item.href}>
      <Link href={`/${lang}${item.href}`} className="block rounded-sm px-3 py-2 font-semibold hover:bg-sand-200">
        {item.label}
      </Link>
    </li>
  ));
  const switcher = (
    <Suspense fallback={<span className="px-3 py-1.5 text-sm">{dict.nav.switchLanguage}</span>}>
      <LanguageSwitch current={lang} label={dict.nav.switchLanguage} />
    </Suspense>
  );

  return (
    <header className="sticky top-0 z-30 border-b border-sand-300 bg-sand-0/95 backdrop-blur">
      <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <Link href={`/${lang}`} aria-label={dict.meta.siteName} className="shrink-0">
          <Logo />
        </Link>
        <nav aria-label={dict.nav.menu} className="hidden lg:block">
          <ul className="flex items-center gap-1">{links}</ul>
        </nav>
        <div className="hidden items-center gap-2 lg:flex">
          <Link href={`/${lang}/account`} className="rounded-sm bg-night px-3 py-1.5 text-sm font-semibold text-on-night">{dict.nav.account}</Link>
          {switcher}
        </div>

        {/* قائمة الجوال دون JavaScript */}
        <details className="group lg:hidden">
          <summary className="cursor-pointer list-none rounded-sm border border-sand-300 px-3 py-2 font-semibold [&::-webkit-details-marker]:hidden">
            <span className="group-open:hidden">{dict.nav.menu}</span>
            <span className="hidden group-open:inline">{dict.nav.close}</span>
          </summary>
          <nav aria-label={dict.nav.menu} className="absolute inset-x-0 top-full border-b border-sand-300 bg-sand-0 px-4 pb-4 shadow-sm">
            <ul className="flex flex-col py-2">
              {links}
              <li><Link href={`/${lang}/account`} className="block rounded-sm px-3 py-2 font-semibold hover:bg-sand-200">{dict.nav.account}</Link></li>
            </ul>
            {switcher}
          </nav>
        </details>
      </div>
    </header>
  );
}
