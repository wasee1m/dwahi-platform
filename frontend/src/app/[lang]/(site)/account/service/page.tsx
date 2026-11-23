import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { SERVICE_COLOR, type ServiceRow } from "@/lib/service-types";
import { statusText } from "./status";

// C-16 طلبات الصيانة (FR-SRV-04، US-19): نشطة، بانتظار موافقتي، مكتملة، ملغاة، بتسمية الحالة للعميل
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/service">;
const TABS = ["active", "awaiting", "done", "closed"] as const;
type Tab = (typeof TABS)[number];

export default async function ServicePage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{t.service.title}</h1>
        <Link href={`/${lang}/service/new`} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{t.service.newTitle}</Link>
      </div>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "active";
  const account = await selectedAccount(await currentUser("customer", lang));
  const res = await authed<ServiceRow[]>("customer", lang, `/me/service-requests?tab=${tab}&${account.query}`);
  const rows = res.ok ? res.data : [];
  const counts = (res.ok ? res.meta.counts : {}) as Partial<Record<Tab, number>>;
  const s = t.service;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={s.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {s.tabs[x]}{counts[x] ? <span className="num ms-1.5 rounded-[999px] bg-sand-200 px-1.5 text-xs">{counts[x]}</span> : null}
          </Link>
        ))}
      </nav>
      {rows.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{s.empty}</p> : (
        <ul className="space-y-3">
          {rows.map((r) => (
            <li key={r.id}>
              <Link href={`/${lang}/account/service/${r.id}`} className="block rounded-md border border-sand-300 bg-sand-0 p-4 hover:border-sun">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-bold"><span className="num" dir="ltr">{r.number}</span> · {r.equipment?.name ?? s.components[r.component_type as keyof typeof s.components]}</p>
                    <p className="mt-1 text-xs text-ink-muted">{r.created_at && date.format(new Date(r.created_at))}{r.urgency === "urgent" && ` · ${s.urgencies.urgent}`}{r.account && ` · ${r.account.name}`}</p>
                  </div>
                  <p className="font-semibold" style={{ color: SERVICE_COLOR[r.customer_status.key] }}>{statusText(r.customer_status, s, lang)}</p>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
