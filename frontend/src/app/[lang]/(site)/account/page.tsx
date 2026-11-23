import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { MyInvitation } from "@/lib/account-types";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import { daysLeft, type RfqRow } from "./quotes/shared";

// C-01 لوحة العميل: "بحاجة لإجراء منك" = العروض التي تنتظر ردك. الطلبات (S6) والصيانة (S7) تُضاف مع وحداتها.
export const metadata = { robots: { index: false } };

export default async function AccountHome({ params }: PageProps<"/[lang]/account">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const a = t.account;

  const quick = [
    { href: `/${lang}/service/new`, label: a.quickService, soon: false },
    { href: `/${lang}/parts`, label: a.quickPart, soon: false },
    { href: `/${lang}/account/quotes/new`, label: a.quickQuote, soon: false },
  ];

  return (
    <div className="space-y-8">
      <h1 className="text-3xl">{a.title}</h1>
      <div className="grid gap-3 sm:grid-cols-3">
        {quick.map((q) => (
          <Link key={q.label} href={q.href} className="rounded-md border border-sand-300 bg-sand-0 p-5 font-bold hover:border-sun">
            {q.label}
            {q.soon && <span className="ms-2 rounded-[999px] bg-sand-200 px-2 py-0.5 text-xs font-semibold text-ink-muted">{a.comingSoon}</span>}
          </Link>
        ))}
      </div>
      <section>
        <h2 className="text-xl">{a.needsAction}</h2>
        <Suspense fallback={<Skeleton className="mt-3 h-24" />}>
          <NeedsAction lang={lang} t={t} />
        </Suspense>
      </section>
    </div>
  );
}

async function NeedsAction({ lang, t }: { lang: Locale; t: AppDictionary }) {
  // من كل الحسابات: ما ينتظر ردك لا يخفيه محدد الحساب
  const [res, invited] = await Promise.all([
    authed<RfqRow[]>("customer", lang, "/me/rfqs?tab=awaiting"),
    authed<MyInvitation[]>("customer", lang, "/me/invitations"),
  ]);
  const rows = res.ok ? res.data : [];
  const invitations = invited.ok ? invited.data : [];
  if (!rows.length && !invitations.length) return <p className="mt-3 rounded-md border border-dashed border-sand-300 bg-sand-0 p-6 text-ink-muted">{t.account.nothingYet}</p>;
  return (
    <ul className="mt-3 space-y-2">
      {invitations.map((i) => (
        <li key={i.id}>
          <Link href={`/${lang}/account/company`} className="block rounded-md border border-sun bg-sand-0 p-4 font-semibold">
            {fill(t.company.invitedTo, { company: i.company })}
          </Link>
        </li>
      ))}
      {rows.map((r) => {
        const left = r.quotation?.valid_until ? daysLeft(r.quotation.valid_until) : null;
        return (
          <li key={r.id}>
            <Link href={`/${lang}/account/quotes/${r.id}`} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-sun bg-sand-0 p-4">
              <span><span className="num font-bold" dir="ltr">{r.quotation?.number ?? r.number}</span> · {r.summary.join("، ")}{r.account && <span className="text-ink-muted"> · {r.account.name}</span>}</span>
              <span className="text-end">
                {r.quotation && <span className="num block font-bold">{formatMoney(r.quotation.total, lang)}</span>}
                {left !== null && <span className="block text-xs font-semibold text-[var(--status-waiting)]">{left <= 0 ? t.quotes.lastDay : fill(t.quotes.daysLeft, { n: String(left) })}</span>}
              </span>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
