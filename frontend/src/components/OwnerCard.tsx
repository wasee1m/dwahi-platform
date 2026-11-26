import type { Locale } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { listBranches } from "@/lib/api";
import { formatPhone, telLink, whatsappLink } from "@/lib/format";
import { safe } from "@/lib/safe";
import { buttonStyles } from "./ui";

// The company owner, shown prominently on About and Contact. On Contact it also offers the main branch phone and WhatsApp.
export async function OwnerCard({ lang, variant }: { lang: Locale; variant: "about" | "contact" }) {
  const dict = await getDictionary(lang);
  const o = dict.owner;
  const main = variant === "contact" ? (await safe(listBranches(lang), []))[0] : undefined;

  return (
    <section aria-labelledby="owner-name" className="rounded-md bg-night text-on-night">
      <div className="flex flex-col gap-6 p-6 sm:flex-row sm:items-center md:p-8">
        <div aria-hidden="true" className="flex size-20 shrink-0 items-center justify-center rounded-full bg-sun font-display text-4xl font-bold text-on-sun md:size-24 md:text-5xl">
          {o.name.trim().charAt(0)}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-bold tracking-wide text-sun">{o.label}</p>
          <h2 id="owner-name" className="mt-1 text-3xl md:text-4xl">{o.name}</h2>
          <p className="mt-1 text-xl font-bold text-sun md:text-2xl">{o.kunya}</p>
          <p className="mt-3 max-w-2xl opacity-90">{variant === "about" ? o.about : o.contact}</p>
          {main?.phone && (
            <div className="mt-5 flex flex-wrap gap-3">
              <a href={telLink(main.phone)} className={buttonStyles.primary}>
                {o.call} <span className="num" dir="ltr">{formatPhone(main.phone)}</span>
              </a>
              {main.whatsapp_sales && (
                <a href={whatsappLink(main.whatsapp_sales)} rel="noopener"
                  className="inline-flex items-center justify-center gap-2 rounded-sm border border-on-night px-5 py-3 font-bold text-on-night transition hover:bg-on-night hover:text-night">
                  {o.whatsapp}
                </a>
              )}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
