import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import type { CustomerProfile } from "@/lib/account-types";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import type { AdminRfqRow } from "../../rfqs/shared";
import { activateCustomer } from "../../customer-actions";
import { SuspendForm } from "../CustomerForms";
import type { InvoiceRow, OrderRow } from "@/lib/order-types";
import type { ServiceRow } from "@/lib/service-types";
import { card, Notes, Records } from "../Sections";
import { CUSTOMER_COLOR } from "../shared";

// A-12 ملف العميل 360° (FR-ADM-05، US-45). التعليق لمدير النظام وحده (SM-7).
type Props = PageProps<"/[lang]/admin/customers/[id]">;

export default function CustomerPage({ params }: Props) {
  return (
    <div className="max-w-5xl space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <View params={params} />
      </Suspense>
    </div>
  );
}

async function View({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("customers.view")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;
  const res = await authed<CustomerProfile>("admin", lang, `/admin/customers/${id}`);
  if (!res.ok) notFound();
  const u = res.data;
  const c = t.admin.customers;
  const date = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeZone: "Asia/Muscat" });
  const facts: [string, string][] = [
    [c.joined, date.format(new Date(u.created_at ?? 0))],
    [c.lastLogin, u.last_login_at ? date.format(new Date(u.last_login_at)) : "—"],
    [c.sessions, String(u.active_sessions)],
    [c.noShows, String(u.pickup_no_show_count)],
  ];

  return (
    <>
      <Link href={`/${lang}/admin/customers`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-3xl">{u.full_name || c.noName}</h1>
          <p className="mt-1 font-semibold" style={{ color: CUSTOMER_COLOR[u.status] }}>
            {c.statuses[u.status]}{u.flagged && <span className="ms-2 text-danger">{c.flagged}</span>}
          </p>
        </div>
        <div className="text-sm">
          {u.phone && <a href={`tel:${u.phone}`} className="num block font-semibold" dir="ltr">{formatPhone(u.phone)}</a>}
          {u.email && <p dir="ltr">{u.email}</p>}
          {u.email && <p className="text-xs text-ink-muted">{u.email_verified ? c.emailVerified : c.emailUnverified}</p>}
        </div>
      </div>
      {u.status === "suspended" && u.suspended_reason && <Notice tone="danger">{c.suspendReason}: {u.suspended_reason}</Notice>}
      {u.status === "deleted" && <Notice>{u.anonymized_at ? c.anonymized : c.deleted}</Notice>}

      <section className={card}>
        <dl className="grid gap-3 text-sm sm:grid-cols-4">
          {facts.map(([label, value]) => <div key={label}><dt className="text-ink-muted">{label}</dt><dd className="font-semibold">{value}</dd></div>)}
        </dl>
        {u.organizations.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-2 text-sm">
            {u.organizations.map((o) => (
              <li key={o.id}>
                <Link href={`/${lang}/admin/customers/companies/${o.id}`} className="inline-block rounded-[999px] bg-sand-200 px-3 py-1 font-semibold">{o.name} · {c.roles[o.role]}</Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      {me.permissions.includes("customers.suspend") && u.status !== "deleted" && (
        <section className={card}>
          {u.status === "active"
            ? <SuspendForm lang={lang} t={c} id={u.id} />
            : <ActionButton action={activateCustomer} fields={{ lang, id: u.id }} label={c.activate} />}
        </section>
      )}

      <Notes lang={lang} t={t} id={u.id} subject="customer" notes={u.notes} />
      <Records lang={lang} t={t} requests={u.requests as unknown as AdminRfqRow[]} conversations={u.conversations} fleet={u.fleet} addresses={u.addresses}
        canOpenRequests={me.permissions.includes("quotation.send")}
        orders={u.orders as unknown as OrderRow[]} invoices={u.invoices as unknown as InvoiceRow[]}
        canOpenOrders={me.permissions.includes("orders.deliver")} canOpenInvoices={me.permissions.includes("invoices.manage")}
        services={u.service_requests as unknown as ServiceRow[]} canOpenServices={me.permissions.includes("service.triage")} />
    </>
  );
}
