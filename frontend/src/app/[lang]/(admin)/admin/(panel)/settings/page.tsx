import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { BranchForm, SettingsGroupForm, type Branch, type SettingRow } from "./SettingsForms";

// A-18 الإعدادات والفروع (FR-ADM-02، FR-ADM-03، US-40): للمدير فقط. تبويب في الرابط (?tab=)
type Props = PageProps<"/[lang]/admin/settings">;
const TABS = ["general", "branches", "pricing", "quotes", "orders", "service", "numbering"] as const;
type Tab = (typeof TABS)[number];

export default async function SettingsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-5xl space-y-6">
      <h1 className="text-3xl">{t.admin.settings.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Settings lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Settings({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("settings.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "general";
  const s = t.admin.settings;
  const c = t.admin.common;
  const gov = t.onboarding.governorates;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  let body;
  if (tab === "branches") {
    const r = await authed<Branch[]>("admin", lang, "/admin/branches");
    const branches = r.ok ? r.data : [];
    body = (
      <div className="space-y-3">
        <details className="rounded-md border border-sand-300 bg-sand-0">
          <summary className="cursor-pointer px-4 py-3 font-semibold hover:bg-sand-100">+ {s.branch.new}</summary>
          <div className="border-t border-sand-300 p-4"><BranchForm key={`new-${branches.length}`} lang={lang} t={s} c={c} gov={gov} /></div>
        </details>
        {branches.map((b) => (
          <details key={b.id} className="rounded-md border border-sand-300 bg-sand-0" open={branches.length === 1}>
            <summary className="cursor-pointer px-4 py-3 hover:bg-sand-100">
              <span className="num me-2 font-bold" dir="ltr">{b.code}</span><span className="font-semibold">{lang === "ar" ? b.name_ar : b.name_en}</span>
              <span className="ms-2 text-sm text-ink-muted">{gov[b.governorate as keyof typeof gov]}{b.is_active ? "" : ` · ${s.branch.inactive}`}</span>
            </summary>
            <div className="border-t border-sand-300 p-4"><BranchForm lang={lang} t={s} c={c} gov={gov} branch={b} /></div>
          </details>
        ))}
      </div>
    );
  } else {
    const r = await authed<SettingRow[]>("admin", lang, "/admin/settings");
    const rows = (r.ok ? r.data : []).filter((x) => x.group === tab);
    const stamps = Object.fromEntries(rows.filter((x) => x.updated_at).map((x) => [x.key, when.format(new Date(x.updated_at!))]));
    body = <SettingsGroupForm key={tab} lang={lang} t={s} c={c} gov={gov} rows={rows} when={stamps} />;
  }

  return (
    <>
      <nav className="flex flex-wrap gap-1 border-b border-sand-300" aria-label={s.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 font-semibold ${x === tab ? "border-sun text-ink" : "border-transparent text-ink-muted hover:text-ink"}`}>{s.groups[x]}</Link>
        ))}
      </nav>
      {tab === "general" && <Notice>{s.notifications}</Notice>}
      {body}
    </>
  );
}
