import type { Metadata } from "next";
import { notFound } from "next/navigation";
import "../../globals.css";
import { fontVariables } from "../../fonts";
import { dirOf, hasLocale, locales } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";

// إطار جذري منفصل للوحة الإدارة (06 §3): لا ترويسة الموقع العام ولا تذييله، ولا فهرسة
export async function generateStaticParams() {
  return locales.map((lang) => ({ lang }));
}

export async function generateMetadata({ params }: LayoutProps<"/[lang]">): Promise<Metadata> {
  const { lang } = await params;
  if (!hasLocale(lang)) return {};
  const t = await getAppDictionary(lang);
  return { title: { default: t.admin.panel, template: `%s | ${t.admin.panel}` }, robots: { index: false, follow: false } };
}

export default async function AdminRootLayout({ children, params }: LayoutProps<"/[lang]">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  return (
    <html lang={lang} dir={dirOf(lang)} className={fontVariables}>
      <body className="min-h-dvh bg-sand-100">{children}</body>
    </html>
  );
}
