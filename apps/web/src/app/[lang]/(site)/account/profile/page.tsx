import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { endSession, logoutEverywhere } from "../actions";
import { PasswordForm, PersonalForm, PhoneForm } from "./ProfileForms";

// C-02 الملف الشخصي والأمان (FR-ACC-01، FR-AUTH-04، US-07، US-08). الصورة الشخصية تأتي مع رفع الملفات.
export const metadata = { robots: { index: false } };

type SessionRow = { id: string; client: "web" | "admin" | "customer_app" | "technician_app"; platform: string | null; user_agent: string | null; last_used_at: string | null; current: boolean };

export default async function ProfilePage({ params }: PageProps<"/[lang]/account/profile">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.profile.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Forms lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Forms({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const [me, sessions] = await Promise.all([currentUser("customer", lang), authed<SessionRow[]>("customer", lang, "/me/sessions")]);
  const p = t.profile;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <PersonalForm lang={lang} t={p} me={me} />
      <PhoneForm lang={lang} t={p} me={me} codeLabel={t.auth.devCode} />
      <PasswordForm lang={lang} t={p} me={me} />

      <section className="rounded-md border border-sand-300 bg-sand-0 p-6">
        <h2 className="text-lg">{p.sessionsTitle}</h2>
        <ul className="mt-4 divide-y divide-sand-300">
          {(sessions.ok ? sessions.data : []).map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div>
                <p className="font-semibold">
                  {p.clients[s.client]}{s.platform ? ` · ${s.platform}` : ""}
                  {s.current && <span className="ms-2 rounded-[999px] bg-sand-200 px-2 py-0.5 text-xs">{p.thisDevice}</span>}
                </p>
                <p className="text-xs text-ink-muted" dir="auto">{s.user_agent?.slice(0, 80)}</p>
                {s.last_used_at && <p className="text-xs text-ink-muted">{p.lastUsed}: {when.format(new Date(s.last_used_at))}</p>}
              </div>
              {!s.current && (
                <form action={endSession}>
                  <input type="hidden" name="lang" value={lang} />
                  <input type="hidden" name="id" value={s.id} />
                  <button className="rounded-sm border border-ink px-4 py-1.5 text-sm font-semibold hover:bg-sand-200">{p.end}</button>
                </form>
              )}
            </li>
          ))}
        </ul>
        <form action={logoutEverywhere} className="mt-4">
          <input type="hidden" name="lang" value={lang} />
          <button className="rounded-sm bg-night px-5 py-2.5 text-sm font-bold text-on-night">{p.logoutAll}</button>
        </form>
      </section>
    </>
  );
}
