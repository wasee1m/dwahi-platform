import type { Metadata } from "next";
import { locales, type Locale } from "@/i18n/config";

export const siteUrl = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

// روابط hreflang لكل صفحة باللغتين (NFR-22). path يبدأ بـ "/" ودون بادئة اللغة.
export function alternates(locale: Locale, path: string): Metadata["alternates"] {
  const clean = path === "/" ? "" : path;
  return {
    canonical: `/${locale}${clean}`,
    languages: Object.fromEntries(locales.map((l) => [l, `/${l}${clean}`])),
  };
}
