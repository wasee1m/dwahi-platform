import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { openStaffNotification, readAllStaffNotifications } from "../rfq-actions";

// إشعارات الموظف: طلب جديد (N-01)، ورد العميل (N-03)، ورسالة (N-17). نفس مركز الإشعارات للعميل (US-23).
type Item = { id: string; title: string; body: string; link: string; read: boolean; created_at: string };

export default async function StaffNotificationsPage({ params }: PageProps<"/[lang]/admin/notifications">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-4xl space-y-6">
      <h1 className="text-3xl">{t.notifications.title}</h1>
      <Suspense fallback={<Skeleton className="h-80" />}>
        <List lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const res = await authed<Item[]>("admin", lang, "/admin/notifications");
  const items = res.ok ? res.data : [];
  const unread = res.ok ? Number(res.meta.unread_count ?? 0) : 0;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      {unread > 0 && (
        <form action={readAllStaffNotifications}>
          <input type="hidden" name="lang" value={lang} />
          <button className="rounded-sm border border-ink px-4 py-2 text-sm font-semibold">{t.notifications.markAll} (<span className="num">{unread}</span>)</button>
        </form>
      )}
      {items.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{t.notifications.empty}</p> : (
        <ul className="divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
          {items.map((i) => (
            <li key={i.id}>
              <form action={openStaffNotification}>
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
      )}
    </>
  );
}
