import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { Company, MyInvitation } from "@/lib/account-types";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone } from "@/lib/format";
import { answerInvitation, removeMember, revokeInvitation } from "../portal-actions";
import { CompanyForm, InviteForm, MemberForm } from "./CompanyForms";

// C-03 الشركة والأعضاء (FR-ACC-02، US-09): بياناتها، وحد الموافقة المسبقة، والأعضاء، والدعوات
export const metadata = { robots: { index: false } };
const card = "rounded-md border border-sand-300 bg-sand-0 p-5";

export default async function CompanyPage({ params }: PageProps<"/[lang]/account/company">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.company.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Content lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function Content({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const [me, companies, invitations] = await Promise.all([
    currentUser("customer", lang),
    authed<Company[]>("customer", lang, "/me/organizations"),
    authed<MyInvitation[]>("customer", lang, "/me/invitations"),
  ]);
  if (!companies.ok || !invitations.ok) return <Notice tone="danger">{t.auth.genericError}</Notice>;
  const c = t.company;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });

  return (
    <>
      {invitations.data.map((i) => (
        <section key={i.id} className="rounded-md border-2 border-sun bg-sand-0 p-5">
          <h2 className="text-lg">{fill(c.invitedTo, { company: i.company })}</h2>
          <p className="mt-1 text-sm text-ink-muted">{fill(c.invitedBy, { name: i.invited_by })} · {c.roles[i.role]} · {fill(c.expires, { date: date.format(new Date(i.expires_at)) })}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <ActionButton action={answerInvitation} fields={{ lang, invitation: i.id, answer: "accept" }} label={c.accept} look="primary" />
            <ActionButton action={answerInvitation} fields={{ lang, invitation: i.id, answer: "decline" }} label={c.decline} />
          </div>
        </section>
      ))}

      {companies.data.map((company) => <CompanySection key={company.id} lang={lang} t={t} company={company} myId={me.id} date={date} />)}
      {companies.data.length === 0 && <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-ink-muted">{c.empty}</p>}

      <details key={companies.data.length} className={card} open={companies.data.length === 0}>
        <summary className="cursor-pointer font-bold">{c.newCompany}</summary>
        <div className="mt-4"><CompanyForm lang={lang} t={c} governorates={t.onboarding.governorates} /></div>
      </details>
    </>
  );
}

function CompanySection({ lang, t, company, myId, date }: { lang: Locale; t: AppDictionary; company: Company; myId: string; date: Intl.DateTimeFormat }) {
  const c = t.company;
  const manage = company.can_manage;
  return (
    <section className={`${card} space-y-5`}>
      <h2 className="text-xl">{company.name}</h2>
      {manage ? <CompanyForm lang={lang} t={c} governorates={t.onboarding.governorates} company={company} /> : (
        <dl className="grid gap-2 text-sm sm:grid-cols-2">
          <div><dt className="text-ink-muted">{c.governorate}</dt><dd>{t.onboarding.governorates[company.governorate as keyof AppDictionary["onboarding"]["governorates"]]}</dd></div>
          <div><dt className="text-ink-muted">{c.cr}</dt><dd className="num">{company.cr_number ?? "—"}</dd></div>
          <div><dt className="text-ink-muted">{c.preapprovalShort}</dt><dd className="num">{formatMoney(company.preapproval_limit, lang)}</dd></div>
        </dl>
      )}

      <div className="border-t border-sand-300 pt-4">
        <h3 className="font-bold">{c.members}</h3>
        <ul className="divide-y divide-sand-300">
          {company.members.map((m) => {
            const self = m.user_id === myId;
            return (
              <li key={m.user_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                  <p className="font-semibold">{m.name}{self && <span className="ms-2 text-xs text-ink-muted">({c.you})</span>}</p>
                  {m.phone && <p className="num text-xs text-ink-muted" dir="ltr">{formatPhone(m.phone)}</p>}
                  {!manage && <p className="text-xs text-ink-muted">{c.roles[m.role]}{m.can_approve_quotes ? ` · ${c.canApprove}` : ""}</p>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {manage && <MemberForm key={`${m.role}-${m.can_approve_quotes}`} lang={lang} t={c} company={company.id} member={m} />}
                  {(manage || self) && (
                    <ActionButton action={removeMember} fields={{ lang, company: company.id, member: m.user_id }} look="danger"
                      label={self ? c.leave : c.remove}
                      confirm={{ question: self ? c.leaveQuestion : c.removeQuestion, yes: c.yes, no: c.no }} />
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      </div>

      {manage && (
        <div className="space-y-4 border-t border-sand-300 pt-4">
          {company.invitations.length > 0 && (
            <div>
              <h3 className="font-bold">{c.pending}</h3>
              <ul className="divide-y divide-sand-300">
                {company.invitations.map((i) => (
                  <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span><span className="num" dir="ltr">{i.phone && formatPhone(i.phone)}</span> · {c.roles[i.role]} · {fill(c.expires, { date: date.format(new Date(i.expires_at)) })}</span>
                    <ActionButton action={revokeInvitation} fields={{ lang, company: company.id, invitation: i.id }} label={c.revoke} />
                  </li>
                ))}
              </ul>
            </div>
          )}
          <InviteForm lang={lang} t={c} company={company.id} />
        </div>
      )}
    </section>
  );
}
