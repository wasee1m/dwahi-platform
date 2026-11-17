import Link from "next/link";
import { notFound } from "next/navigation";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";

// C-01 لوحة العميل. "بحاجة لإجراء منك" والطلبات النشطة تمتلئ مع العروض (S4) والطلبات (S6) والصيانة (S7).
export const metadata = { robots: { index: false } };

export default async function AccountHome({ params }: PageProps<"/[lang]/account">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = (await getAppDictionary(lang)).account;

  const quick = [
    { href: `/${lang}/services`, label: t.quickService, soon: true },
    { href: `/${lang}/parts`, label: t.quickPart, soon: false },
    { href: `/${lang}/equipment`, label: t.quickQuote, soon: true },
  ];

  return (
    <div className="space-y-8">
      <h1 className="text-3xl">{t.title}</h1>
      <div className="grid gap-3 sm:grid-cols-3">
        {quick.map((q) => (
          <Link key={q.label} href={q.href} className="rounded-md border border-sand-300 bg-sand-0 p-5 font-bold hover:border-sun">
            {q.label}
            {q.soon && <span className="ms-2 rounded-[999px] bg-sand-200 px-2 py-0.5 text-xs font-semibold text-ink-muted">{t.comingSoon}</span>}
          </Link>
        ))}
      </div>
      <section>
        <h2 className="text-xl">{t.needsAction}</h2>
        <p className="mt-3 rounded-md border border-dashed border-sand-300 bg-sand-0 p-6 text-ink-muted">{t.nothingYet}</p>
      </section>
    </div>
  );
}
