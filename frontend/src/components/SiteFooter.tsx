import Link from "next/link";
import type { Locale } from "@/i18n/config";
import type { Dictionary } from "@/i18n/get-dictionary";
import { listBranches } from "@/lib/api";
import { formatPhone, telLink, whatsappLink } from "@/lib/format";
import { safe } from "@/lib/safe";
import { Logo } from "./Logo";

// التذييل (06 §4.1). الرقم الضريبي والسجل التجاري يُضافان بعد تأكيدهما (Q-17).
export async function SiteFooter({ lang, dict }: { lang: Locale; dict: Dictionary }) {
  const branches = await safe(listBranches(lang), []);
  const main = branches[0];
  const link = (href: string, label: string) => (
    <li>
      <Link href={`/${lang}${href}`} className="hover:text-sun">{label}</Link>
    </li>
  );

  return (
    <footer className="mt-16 bg-night text-on-night">
      <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-4">
        <div className="md:col-span-2">
          <Logo inverse />
          <p className="mt-4 max-w-sm text-sm opacity-85">{dict.meta.tagline}</p>
          {main && (
            <address className="mt-4 text-sm not-italic opacity-85">
              <p>{main.address ?? dict.footer.address}</p>
              {main.phone && (
                <p className="mt-1">
                  <a href={telLink(main.phone)} className="num hover:text-sun">{formatPhone(main.phone)}</a>
                  {main.whatsapp_sales && (
                    <>
                      {" · "}
                      <a href={whatsappLink(main.whatsapp_sales)} className="hover:text-sun" rel="noopener">{dict.nav.whatsapp}</a>
                    </>
                  )}
                </p>
              )}
              {main.email && <p className="mt-1"><a href={`mailto:${main.email}`} className="hover:text-sun">{main.email}</a></p>}
            </address>
          )}
        </div>
        <div>
          <h2 className="mb-3 text-sm">{dict.footer.links}</h2>
          <ul className="space-y-2 text-sm opacity-90">
            {link("/equipment", dict.nav.equipment)}
            {link("/parts", dict.nav.parts)}
            {link("/services", dict.nav.services)}
            {link("/branches", dict.nav.branches)}
            {link("/faq", dict.footer.faq)}
          </ul>
        </div>
        <div>
          <h2 className="mb-3 text-sm">{dict.footer.policies}</h2>
          <ul className="space-y-2 text-sm opacity-90">
            {link("/terms", dict.footer.terms)}
            {link("/privacy", dict.footer.privacy)}
            {link("/returns", dict.footer.returns)}
            {link("/warranty", dict.footer.warranty)}
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10">
        <p className="mx-auto max-w-6xl px-4 py-4 text-xs opacity-70">
          © DWAHI-ALBADIA LLC · {dict.footer.rights}
        </p>
      </div>
    </footer>
  );
}
