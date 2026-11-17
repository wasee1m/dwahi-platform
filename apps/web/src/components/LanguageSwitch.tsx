"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import type { Locale } from "@/i18n/config";

// مبدل اللغة يحافظ على نفس الصفحة ومعاملات الرابط (06 §4.1)، ويحفظ الاختيار في كوكي للزيارة التالية.
export function LanguageSwitch({ current, label }: { current: Locale; label: string }) {
  const pathname = usePathname();
  const search = useSearchParams();
  const target: Locale = current === "ar" ? "en" : "ar";
  const rest = pathname.replace(/^\/(ar|en)(?=\/|$)/, "");
  const query = search.toString();
  const href = `/${target}${rest}${query ? `?${query}` : ""}`;

  return (
    <Link
      href={href}
      hrefLang={target}
      lang={target}
      onClick={() => {
        document.cookie = `NEXT_LOCALE=${target}; path=/; max-age=31536000; samesite=lax`;
      }}
      className="rounded-sm border border-sand-300 px-3 py-1.5 text-sm font-semibold hover:bg-sand-200"
    >
      {label}
    </Link>
  );
}
