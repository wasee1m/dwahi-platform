import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { FleetItem } from "@/lib/account-types";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatNumber } from "@/lib/format";

// C-05 أسطولي (FR-ACC-05، US-10). "آخر صيانة" تظهر مع وحدة الصيانة (S7).
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/fleet">;

export default async function FleetPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.fleet.title}</h1>
        <Link href={`/${lang}/account/fleet/new`} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.fleet.addTitle}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const account = await selectedAccount(await currentUser("customer", lang));
  const qs = new URLSearchParams(account.query);
  const q = typeof sp.q === "string" ? sp.q.trim() : "";
  if (q) qs.set("q", q);
  if (typeof sp.cursor === "string") qs.set("cursor", sp.cursor);
  const res = await authed<FleetItem[]>("customer", lang, `/me/fleet?${qs}`);
  if (!res.ok) return <Notice tone="danger">{t.auth.genericError}</Notice>;
  const f = t.fleet;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const next = res.meta.next_cursor as string | null;

  return (
    <>
      <form method="get" role="search" className="flex max-w-lg gap-2">
        <input name="q" type="search" aria-label={f.search} placeholder={f.searchHint} defaultValue={q}
          className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        <button className="rounded-sm bg-night px-4 py-2 font-bold text-on-night">{f.search}</button>
      </form>
      {res.data.length === 0 ? (
        <div className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">
          <p>{q ? f.noMatch : f.empty}</p>
          {!q && <Link href={`/${lang}/account/fleet/new`} className="mt-3 inline-block font-bold text-sun-text underline">{f.addTitle}</Link>}
        </div>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {res.data.map((e) => (
            <li key={e.id}>
              <Link href={`/${lang}/account/fleet/${e.id}`} className="block h-full rounded-md border border-sand-300 bg-sand-0 p-4 hover:border-sun">
                <p className="font-bold">{e.nickname ?? e.machine}</p>
                {e.nickname && <p className="text-sm text-ink-muted">{e.machine}</p>}
                {e.serial_number && <p className="num text-xs text-ink-muted" dir="ltr">{e.serial_number}</p>}
                <p className="mt-2 text-sm">
                  {e.hour_meter === null ? f.noReading : fill(f.hours, { n: formatNumber(e.hour_meter) })}
                  {e.hour_meter_at && <span className="text-ink-muted"> · {date.format(new Date(e.hour_meter_at))}</span>}
                </p>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {next && <Link href={`?${new URLSearchParams({ ...(q ? { q } : {}), cursor: next })}`} className="font-semibold text-sun-text underline">{f.more}</Link>}
    </>
  );
}
