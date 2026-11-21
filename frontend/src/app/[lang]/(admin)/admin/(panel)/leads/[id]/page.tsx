import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import { ConvertLead, LeadForm } from "../LeadForm";
import { LEAD_COLOR, type LeadRow } from "../shared";

// A-15 تفاصيل استفسار: الرسالة، والتواصل المباشر، والحالة والإسناد، والسجل باسم الموظف ووقته (US-43)
type Props = PageProps<"/[lang]/admin/leads/[id]">;
type Lead = LeadRow & {
  email: string | null; message: string; source_page: string | null;
  notes: { id: string; body: string | null; status_from: LeadRow["status"] | null; status_to: LeadRow["status"] | null; by: string | null; at: string }[];
};

export default function LeadPage({ params }: Props) {
  return (
    <div className="max-w-4xl space-y-6">
      <Suspense fallback={<Skeleton className="h-[600px]" />}>
        <Detail params={params} />
      </Suspense>
    </div>
  );
}

async function Detail({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("leads.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const [res, people] = await Promise.all([
    authed<Lead>("admin", lang, `/admin/leads/${encodeURIComponent(id)}`),
    authed<{ id: string; full_name: string }[]>("admin", lang, "/admin/leads/assignees"),
  ]);
  if (!res.ok) notFound();
  const lead = res.data;
  const l = t.admin.leads;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });
  const wa = lead.phone.replace(/\D/g, "");

  return (
    <>
      <Link href={`/${lang}/admin/leads`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-3xl">{lead.name}</h1>
          <p className="mt-1 text-ink-muted">
            {lead.company && <>{lead.company} · </>}{l.topics[lead.topic]} · {when.format(new Date(lead.created_at))}
            {" · "}<span className="font-semibold" style={{ color: LEAD_COLOR[lead.status] }}>{l.statuses[lead.status]}</span>
          </p>
        </div>
        <div className="flex gap-2">
          <a href={`tel:${lead.phone}`} className="rounded-sm border border-ink px-4 py-2 font-semibold">{l.call} <span className="num" dir="ltr">{formatPhone(lead.phone)}</span></a>
          <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer" className="rounded-sm bg-success px-4 py-2 font-semibold text-white">{l.whatsapp}</a>
        </div>
      </div>

      <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="mb-2 text-sm font-semibold text-ink-muted">{l.message}</h2>
        <p className="whitespace-pre-line">{lead.message}</p>
        <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-3">
          {lead.email && <div><dt className="text-ink-muted">{l.email}</dt><dd dir="ltr" className="text-start">{lead.email}</dd></div>}
          {lead.branch && <div><dt className="text-ink-muted">{l.branch}</dt><dd>{lead.branch}</dd></div>}
          {lead.source_page && <div><dt className="text-ink-muted">{l.source}</dt><dd dir="ltr" className="num truncate text-start">{lead.source_page}</dd></div>}
        </dl>
      </section>

      <LeadForm key={`${lead.status}-${lead.notes.length}`} lang={lang} t={l} lead={lead} assignees={people.ok ? people.data : []} />
      {lead.topic !== "service" && lead.status !== "closed" && me.permissions.includes("rfq.create_on_behalf") && (
        <ConvertLead lang={lang} label={l.convert} lead={lead} />
      )}
      {lead.topic === "service" && <Notice>{l.toRfq}</Notice>}

      <section>
        <h2 className="mb-3 text-xl">{l.history}</h2>
        {lead.notes.length === 0 ? <p className="text-ink-muted">{t.admin.common.none}</p> : (
          <ol className="space-y-3 border-s-2 border-sand-300 ps-4">
            {lead.notes.map((n) => (
              <li key={n.id}>
                <p className="text-sm text-ink-muted">
                  <span className="font-semibold text-ink">{n.by}</span> · {when.format(new Date(n.at))}
                  {n.status_from && n.status_to && <> · {l.changed.replace("{from}", l.statuses[n.status_from]).replace("{to}", l.statuses[n.status_to])}</>}
                </p>
                {n.body && <p className="mt-1 whitespace-pre-line">{n.body}</p>}
              </li>
            ))}
          </ol>
        )}
      </section>
    </>
  );
}
