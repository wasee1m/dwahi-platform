import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import type { ServiceDetail, ServiceRow } from "@/lib/service-types";
import { findByCard } from "../service-actions";
import { IntakeForm, WalkIn } from "./DeskForms";

// A-22 الاستلام في الورشة (FR-SRV-08، US-48): بحث بالطلب أو بطاقة الاستلام، وبطاقة الاستلام بصورها وتوقيعها،
// وحضور دون طلب. التسليم مع الإنجاز (S9).
type Props = PageProps<"/[lang]/admin/workshop-desk">;

export default async function DeskPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.service.desk}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Desk lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Desk({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("workshop.handover")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const sp = await searchParams;
  const a = t.admin.service;
  const receive = typeof sp.receive === "string" && /^[0-9a-f-]{36}$/i.test(sp.receive) ? sp.receive : null;
  const q = typeof sp.q === "string" ? sp.q : "";

  if (receive) {
    const res = await authed<ServiceDetail>("admin", lang, `/admin/service-requests/${receive}`);
    if (!res.ok) notFound();
    const r = res.data;
    return (
      <div className="max-w-2xl space-y-4">
        <Link href={`/${lang}/admin/workshop-desk`} className="text-sm font-semibold text-ink-muted">← {a.desk}</Link>
        <div className="rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
          <p className="num font-bold" dir="ltr">{r.number}</p>
          <p>{r.customer?.organization ?? r.customer?.name}{r.customer?.phone && <> · <span className="num" dir="ltr">{formatPhone(r.customer.phone)}</span></>}</p>
          <p>{r.equipment?.name ?? t.service.components[r.component_type as keyof typeof t.service.components]}{r.component_details && ` · ${r.component_details}`}</p>
        </div>
        {r.intake ? <Notice tone="info">{a.alreadyReceived} <span className="num" dir="ltr">{r.intake.number}</span></Notice>
          : <IntakeForm lang={lang} t={a} id={r.id} equipment={!!r.equipment} />}
      </div>
    );
  }

  const res = q ? await authed<ServiceRow[]>("admin", lang, `/admin/service-requests?${new URLSearchParams({ status: "open", q, received: "0" })}`) : null;
  const rows = res?.ok ? res.data : [];

  return (
    <div className="grid gap-8 lg:grid-cols-2">
      <section className="space-y-4">
        <h2 className="text-xl">{a.receiveTitle}</h2>
        <form action={findByCard} className="flex gap-2">
          <input type="hidden" name="lang" value={lang} />
          <input name="q" defaultValue={q} required placeholder={a.deskSearch} aria-label={a.deskSearch} autoFocus
            className="min-w-0 flex-1 rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
          <button className="rounded-sm bg-night px-4 py-2 font-semibold text-on-night">{t.admin.orders.find}</button>
        </form>
        {q && rows.length === 0 && <p className="text-ink-muted">{a.noMatch}</p>}
        <ul className="space-y-2">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-sand-300 bg-sand-0 p-3 text-sm">
              <span><span className="num font-semibold" dir="ltr">{r.number}</span> · {r.customer?.organization ?? r.customer?.name}
                <span className="block text-ink-muted">{r.equipment?.name ?? t.service.components[r.component_type as keyof typeof t.service.components]}</span></span>
              <Link href={`?receive=${r.id}`} className="rounded-sm bg-sun px-4 py-2 font-bold text-on-sun">{a.receive}</Link>
            </li>
          ))}
        </ul>
      </section>
      <section className="space-y-4">
        <h2 className="text-xl">{a.walkIn}</h2>
        <WalkIn lang={lang} t={a} components={t.service.components} urgencies={t.service.urgencies} />
      </section>
    </div>
  );
}
