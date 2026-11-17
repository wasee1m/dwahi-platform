import type { Metadata, Viewport } from "next";
import { notFound } from "next/navigation";
import "../../globals.css";
import { fontVariables } from "../../fonts";
import { SiteFooter } from "@/components/SiteFooter";
import { SiteHeader } from "@/components/SiteHeader";
import { dirOf, hasLocale, locales } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { alternates, siteUrl } from "@/lib/seo";

export async function generateStaticParams() {
  return locales.map((lang) => ({ lang }));
}

export async function generateMetadata({ params }: LayoutProps<"/[lang]">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const dict = await getDictionary(lang);
  return {
    metadataBase: new URL(siteUrl),
    title: { default: `${dict.meta.siteName} | ${dict.meta.tagline}`, template: `%s | ${dict.meta.siteName}` },
    description: dict.meta.description,
    alternates: alternates(lang, "/"),
    openGraph: { siteName: dict.meta.siteName, locale: lang === "ar" ? "ar_OM" : "en_OM", type: "website" },
  };
}

export const viewport: Viewport = { themeColor: "#2B386B" };

export default async function LangLayout({ children, params }: LayoutProps<"/[lang]">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);

  return (
    <html lang={lang} dir={dirOf(lang)} className={fontVariables}>
      <body className="flex min-h-dvh flex-col">
        <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:bg-sun focus:px-4 focus:py-2 focus:text-on-sun">
          {dict.nav.skipToContent}
        </a>
        <SiteHeader lang={lang} dict={dict} />
        <main id="main" className="flex-1">{children}</main>
        <SiteFooter lang={lang} dict={dict} />
      </body>
    </html>
  );
}
