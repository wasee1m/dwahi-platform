import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { BannerForm, FaqForm, FaqOrder, type Banner, type Faq } from "./ContentForms";

// A-14 المحتوى (FR-CMS-01، FR-CMS-03، FR-CMS-04، US-42): تبويب في الرابط (?tab=)
type Props = PageProps<"/[lang]/admin/content">;
type PageRow = { id: string; slug: string; title_ar: string; title_en: string; status: "draft" | "published"; updated_at: string | null; updated_by: string | null };
const TABS = ["pages", "faqs", "banners"] as const;
type Tab = (typeof TABS)[number];

const STATE_COLOR = { live: "var(--status-success)", scheduled: "var(--status-progress)", ended: "var(--status-neutral)", inactive: "var(--status-hold)" } as const;

export default async function ContentPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.content.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Content lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

function Row({ title, sub, children }: { title: ReactNode; sub?: ReactNode; children: ReactNode }) {
  return (
    <details className="group rounded-md border border-sand-300 bg-sand-0">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-sand-100">
        <span className="min-w-0"><span className="font-semibold">{title}</span>{sub && <span className="ms-2 text-sm text-ink-muted">{sub}</span>}</span>
        <span aria-hidden className="text-ink-muted transition group-open:rotate-180">▾</span>
      </summary>
      <div className="border-t border-sand-300 p-4">{children}</div>
    </details>
  );
}

async function Content({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("content.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "pages";
  const s = t.admin.content;
  const c = t.admin.common;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  let body: ReactNode = null;
  if (tab === "pages") {
    const r = await authed<PageRow[]>("admin", lang, "/admin/pages");
    const pages = r.ok ? r.data : [];
    body = (
      <>
        <Link href={`/${lang}/admin/content/pages/new`} className="inline-block rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{s.newPage}</Link>
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-sand-200"><tr>{[s.titleAr, s.slug, s.status, s.updatedBy].map((h) => <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>)}</tr></thead>
            <tbody className="divide-y divide-sand-300">
              {pages.map((p) => (
                <tr key={p.id} className="hover:bg-sand-100">
                  <td className="px-4 py-2"><Link href={`/${lang}/admin/content/pages/${p.id}`} className="font-semibold underline-offset-4 hover:underline">{lang === "ar" ? p.title_ar : p.title_en}</Link></td>
                  <td className="num px-4 py-2" dir="ltr">/{p.slug}</td>
                  <td className="px-4 py-2 font-semibold" style={{ color: p.status === "published" ? "var(--status-success)" : "var(--status-waiting)" }}>{s.statuses[p.status]}</td>
                  <td className="px-4 py-2 text-ink-muted">{p.updated_at ? when.format(new Date(p.updated_at)) : "—"}{p.updated_by ? ` · ${p.updated_by}` : ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </>
    );
  } else if (tab === "faqs") {
    const r = await authed<Faq[]>("admin", lang, "/admin/faqs");
    const faqs = r.ok ? r.data : [];
    body = (
      <>
        <FaqOrder key={faqs.map((f) => f.id).join()} lang={lang} t={s} c={c} faqs={faqs} />
        <div className="space-y-3">
          <Row title={`+ ${s.newFaq}`}><FaqForm key={`new-${faqs.length}`} lang={lang} t={s} c={c} /></Row>
          {faqs.map((f) => (
            <Row key={f.id} title={lang === "ar" ? f.question_ar : f.question_en} sub={`${s.sections[f.section]}${f.is_active ? "" : ` · ${c.no} ${s.active}`}`}>
              <FaqForm lang={lang} t={s} c={c} faq={f} />
            </Row>
          ))}
        </div>
      </>
    );
  } else {
    const r = await authed<Banner[]>("admin", lang, "/admin/banners");
    const banners = r.ok ? r.data : [];
    body = (
      <div className="space-y-3">
        <Row title={`+ ${s.newBanner}`}><BannerForm key={`new-${banners.length}`} lang={lang} t={s} c={c} /></Row>
        {banners.length === 0 && <p className="p-6 text-center text-ink-muted">{c.empty}</p>}
        {banners.map((b) => (
          <Row key={b.id}
            title={<span className="inline-flex items-center gap-3">{(lang === "ar" ? b.image_ar_url : b.image_en_url) && <Image src={(lang === "ar" ? b.image_ar_url : b.image_en_url)!} alt="" width={96} height={32} unoptimized className="h-8 w-24 rounded-sm object-cover" />}{s.placements[b.placement]}</span>}
            sub={<><span style={{ color: STATE_COLOR[b.state] }} className="font-semibold">{s.states[b.state]}</span> · {when.format(new Date(b.starts_at))} ← {when.format(new Date(b.ends_at))}</>}>
            <BannerForm lang={lang} t={s} c={c} banner={b} />
          </Row>
        ))}
      </div>
    );
  }

  return (
    <>
      <nav className="flex gap-1 border-b border-sand-300" aria-label={s.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun text-ink" : "border-transparent text-ink-muted hover:text-ink"}`}>{s[x]}</Link>
        ))}
      </nav>
      <section className="space-y-4">{body}</section>
    </>
  );
}
