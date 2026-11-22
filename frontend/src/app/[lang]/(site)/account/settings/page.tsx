import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { DataExport, Deletion, Preferences } from "@/lib/account-types";
import { authed } from "@/lib/auth/client";
import { requestExport, setMarketing } from "../portal-actions";
import { DeleteAccountForm, PreferencesForm } from "./SettingsForms";

// C-20 الإعدادات والخصوصية (FR-NOT-03، FR-ACC-06، US-11، US-23)
export const metadata = { robots: { index: false } };
const card = "space-y-3 rounded-md border border-sand-300 bg-sand-0 p-5";

export default async function SettingsPage({ params }: PageProps<"/[lang]/account/settings">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.privacySettings.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Content lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Content({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const [prefs, exported, deletion] = await Promise.all([
    authed<Preferences>("customer", lang, "/me/notification-preferences"),
    authed<DataExport>("customer", lang, "/me/data-export"),
    authed<Deletion>("customer", lang, "/me/deletion"),
  ]);
  if (!prefs.ok || !exported.ok || !deletion.ok) return <Notice tone="danger">{t.auth.genericError}</Notice>;
  const s = t.privacySettings;
  const d = deletion.data;
  // api() يعيد الاستجابة كلها حين تكون data فارغة، فالتحقق من المعرّف
  const latest = exported.data?.id ? exported.data : null;
  const consent = prefs.data.marketing_consent;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });

  return (
    <>
      <section className={card}>
        <h2 className="text-xl">{s.notifications}</h2>
        <PreferencesForm key={String(consent)} lang={lang} t={s} data={prefs.data} />
      </section>

      <section className={card}>
        <h2 className="text-xl">{s.marketing}</h2>
        <p className="text-sm text-ink-muted">{consent ? s.marketingOn : s.marketingOff}</p>
        <ActionButton action={setMarketing} fields={{ lang, granted: consent ? "0" : "1" }} label={consent ? s.marketingStop : s.marketingStart} />
      </section>

      <section className={card}>
        <h2 className="text-xl">{s.exportTitle}</h2>
        <p className="text-sm text-ink-muted">{s.exportIntro}</p>
        {latest?.status === "pending" && <Notice>{s.exportPending}</Notice>}
        {latest?.status === "failed" && <Notice tone="danger">{s.exportFailed}</Notice>}
        {latest?.status === "ready" && latest.url && latest.expires_at && (
          <p className="text-sm">
            <a href={latest.url} className="font-bold text-sun-text underline">{s.exportDownload}</a>
            {" · "}{fill(s.exportUntil, { date: date.format(new Date(latest.expires_at)) })}
          </p>
        )}
        {latest?.status !== "pending" && <ActionButton action={requestExport} fields={{ lang }} label={s.exportRequest} />}
      </section>

      <section className={card}>
        <h2 className="text-xl">{s.deleteTitle}</h2>
        <p className="text-sm">{fill(s.deleteIntro, { days: String(d.grace_days) })}</p>
        <p className="text-sm text-ink-muted">{s.deleteFinancial}</p>
        {d.companies.length > 0 && (
          <div className="text-sm">
            <p className="font-semibold text-danger">{s.transferFirst}</p>
            <ul className="list-disc ps-5">
              {d.companies.map((c) => <li key={c.id}><Link href={`/${lang}/account/company`} className="underline">{c.name}</Link></li>)}
            </ul>
          </div>
        )}
        {d.requests.length > 0 && (
          <div className="text-sm">
            <p className="font-semibold text-danger">{s.closeFirst}</p>
            <ul className="list-disc ps-5">
              {d.requests.map((r) => <li key={r.id}><Link href={`/${lang}/account/quotes/${r.id}`} className="num underline" dir="ltr">{r.number}</Link></li>)}
            </ul>
          </div>
        )}
        {d.can_delete && <DeleteAccountForm lang={lang} t={s} codeLabel={t.auth.devCode} />}
      </section>
    </>
  );
}
