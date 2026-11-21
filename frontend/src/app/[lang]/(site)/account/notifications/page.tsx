import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { openNotification, readAllNotifications } from "../quotes/actions";

// C-19 الإشعارات (FR-NOT-01، US-23): مجمعة حسب اليوم، وغير المقروء بارز، وفلترة بالفئة، و"تعليم الكل كمقروء"
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/notifications">;
type Item = { id: string; event: string; category: string | null; title: string; body: string; link: string; read: boolean; created_at: string };
const CATEGORIES = ["quotes", "messages", "orders", "service"] as const;

export default async function NotificationsPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.notifications.title}</h1>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

/** اليوم/أمس/أقدم بتوقيت مسقط */
function bucket(iso: string): "today" | "yesterday" | "older" {
  const day = (d: Date) => d.toLocaleDateString("en-CA", { timeZone: "Asia/Muscat" });
  const now = new Date();
  const d = day(new Date(iso));
  if (d === day(now)) return "today";
  return d === day(new Date(now.getTime() - 86_400_000)) ? "yesterday" : "older";
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const cat = CATEGORIES.includes(sp.category as (typeof CATEGORIES)[number]) ? (sp.category as string) : undefined;
  const res = await authed<Item[]>("customer", lang, `/me/notifications${cat ? `?category=${cat}` : ""}`);
  const items = res.ok ? res.data : [];
  const unread = res.ok ? Number(res.meta.unread_count ?? 0) : 0;
  const n = t.notifications;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { timeStyle: "short", dateStyle: "medium", timeZone: "Asia/Muscat" });
  const groups = (["today", "yesterday", "older"] as const).map((b) => [b, items.filter((i) => bucket(i.created_at) === b)] as const).filter(([, g]) => g.length);

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <nav className="flex flex-wrap gap-2" aria-label={n.title}>
          <Link href="?" aria-current={!cat ? "page" : undefined} className={`rounded-[999px] px-3 py-1 text-sm font-semibold ${!cat ? "bg-night text-on-night" : "bg-sand-200"}`}>{n.all}</Link>
          {CATEGORIES.map((c) => (
            <Link key={c} href={`?category=${c}`} aria-current={cat === c ? "page" : undefined}
              className={`rounded-[999px] px-3 py-1 text-sm font-semibold ${cat === c ? "bg-night text-on-night" : "bg-sand-200"}`}>{n.categories[c]}</Link>
          ))}
        </nav>
        {unread > 0 && (
          <form action={readAllNotifications}>
            <input type="hidden" name="lang" value={lang} />
            <button className="rounded-sm border border-ink px-4 py-2 text-sm font-semibold">{n.markAll} (<span className="num">{unread}</span>)</button>
          </form>
        )}
      </div>

      {items.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{n.empty}</p> : groups.map(([b, g]) => (
        <section key={b}>
          <h2 className="mb-2 text-sm font-semibold text-ink-muted">{n[b]}</h2>
          <ul className="divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
            {g.map((i) => (
              <li key={i.id}>
                <form action={openNotification}>
                  <input type="hidden" name="lang" value={lang} />
                  <input type="hidden" name="id" value={i.id} />
                  <input type="hidden" name="link" value={i.link} />
                  <button className={`block w-full px-4 py-3 text-start hover:bg-sand-100 ${i.read ? "" : "border-s-4 border-sun"}`}>
                    <span className={`block ${i.read ? "" : "font-bold"}`}>{i.title}</span>
                    <span className="mt-0.5 block text-sm text-ink-muted">{i.body}</span>
                    <span className="mt-1 block text-xs text-ink-muted">{time.format(new Date(i.created_at))}</span>
                  </button>
                </form>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </>
  );
}
