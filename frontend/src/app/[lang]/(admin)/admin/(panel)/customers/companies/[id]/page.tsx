import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import type { CompanyProfile } from "@/lib/account-types";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney, formatPhone } from "@/lib/format";
import type { AdminRfqRow } from "../../../rfqs/shared";
import { removeCompanyMember, revokeCompanyInvitation } from "../../../customer-actions";
import { StaffInviteForm, StaffMemberForm } from "../../CustomerForms";
import type { InvoiceRow, OrderRow } from "@/lib/order-types";
import { card, Notes, Records } from "../../Sections";

// A-12 ملف الشركة: الأعضاء، ويديرهم مدير النظام (01 §3.2)، وسجلاتها والملاحظات
type Props = PageProps<"/[lang]/admin/customers/companies/[id]">;

export default function CompanyPage({ params }: Props) {
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
  const res = await authed<CompanyProfile>("admin", lang, `/admin/organizations/${id}`);
  if (!res.ok) notFound();
  const o = res.data;
  const c = t.admin.customers;
  const governorate = t.onboarding.governorates[o.governorate as keyof typeof t.onboarding.governorates];

  return (
    <>
      <Link href={`/${lang}/admin/customers?view=companies`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div>
        <h1 className="text-3xl">{o.name}</h1>
        <p className="mt-1 text-sm text-ink-muted">
          {governorate} · {c.cr}: <span className="num">{o.cr_number ?? "—"}</span> · {c.vat}: <span className="num">{o.vat_number ?? "—"}</span>
          {" · "}{c.preapproval}: <span className="num">{formatMoney(o.preapproval_limit, lang)}</span>
        </p>
      </div>

      <section className={`${card} space-y-3`}>
        <h2 className="text-xl">{c.members}</h2>
        <ul className="divide-y divide-sand-300">
          {o.members.map((m) => (
            <li key={m.user_id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
              <span>
                <Link href={`/${lang}/admin/customers/${m.user_id}`} className="font-semibold underline">{m.name || c.noName}</Link>
                {m.phone && <span className="num ms-2 text-ink-muted" dir="ltr">{formatPhone(m.phone)}</span>}
                {!o.can_manage && <span className="ms-2 text-ink-muted">{c.roles[m.role]}{m.can_approve_quotes && ` · ${c.canApprove}`}</span>}
              </span>
              {o.can_manage && (
                <span className="flex flex-wrap items-center gap-2">
                  <StaffMemberForm key={`${m.role}-${m.can_approve_quotes}`} lang={lang} t={c} company={o.id} member={m} />
                  <ActionButton action={removeCompanyMember} fields={{ lang, id: o.id, member: m.user_id }} label={c.remove} look="danger"
                    confirm={{ question: c.removeQuestion, yes: t.company.yes, no: t.company.no }} />
                </span>
              )}
            </li>
          ))}
        </ul>
        {o.invitations.map((i) => (
          <p key={i.id} className="flex flex-wrap items-center justify-between gap-2 text-sm text-ink-muted">
            <span>{c.pendingInvite}: <span className="num" dir="ltr">{i.phone && formatPhone(i.phone)}</span> · {c.roles[i.role]}</span>
            {o.can_manage && <ActionButton action={revokeCompanyInvitation} fields={{ lang, id: o.id, invitation: i.id }} label={c.revoke} />}
          </p>
        ))}
        {o.can_manage && <StaffInviteForm key={o.invitations.length} lang={lang} t={c} company={o.id} />}
      </section>

      <Notes lang={lang} t={t} id={o.id} subject="company" notes={o.notes} />
      <Records lang={lang} t={t} requests={o.requests as unknown as AdminRfqRow[]} conversations={o.conversations} fleet={o.fleet} addresses={o.addresses}
        canOpenRequests={me.permissions.includes("quotation.send")}
        orders={o.orders as unknown as OrderRow[]} invoices={o.invoices as unknown as InvoiceRow[]}
        canOpenOrders={me.permissions.includes("orders.deliver")} canOpenInvoices={me.permissions.includes("invoices.manage")} />
    </>
  );
}
