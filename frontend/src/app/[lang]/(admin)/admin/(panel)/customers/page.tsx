import { cookies } from "next/headers";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { CompanyRow, CustomerRow } from "@/lib/account-types";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import { searchCustomers } from "../customer-actions";
import { CUSTOMER_COLOR, SEARCH_COOKIE } from "./shared";

// A-12 العملاء والشركات (FR-ADM-05، US-45): بحث بالجوال أو الاسم أو السجل التجاري
type Props = PageProps<"/[lang]/admin/customers">;

export default async function CustomersPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.customers.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("customers.view")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const companies = sp.view === "companies";
  const q = (await cookies()).get(SEARCH_COOKIE)?.value ?? "";
  const qs = new URLSearchParams();
  if (q) qs.set("q", q);
  if (typeof sp.cursor === "string") qs.set("cursor", sp.cursor);
  const res = await authed<(CustomerRow | CompanyRow)[]>("admin", lang, `/admin/${companies ? "organizations" : "customers"}?${qs}`);
  if (!res.ok) return <Notice tone="danger">{res.error.message}</Notice>;

  const c = t.admin.customers;
  const next = res.meta.next_cursor as string | null;
  const base = `/${lang}/admin/customers`;
  const tab = (on: boolean) => `-mb-px border-b-2 px-4 py-2 font-semibold ${on ? "border-sun" : "border-transparent text-ink-muted"}`;

  return (
    <>
      <form action={searchCustomers} role="search" className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="view" value={companies ? "companies" : "customers"} />
        <label className="text-sm">
          <span className="mb-1 block font-semibold">{c.searchLabel}</span>
          <input key={q} name="q" defaultValue={q} className="w-80 max-w-full rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" />
        </label>
        <button className="rounded-sm bg-night px-5 py-2 font-bold text-on-night">{t.admin.common.search}</button>
      </form>

      <nav className="flex gap-1 border-b border-sand-300" aria-label={c.title}>
        <Link href={base} aria-current={companies ? undefined : "page"} className={tab(!companies)}>{c.people}</Link>
        <Link href={`${base}?view=companies`} aria-current={companies ? "page" : undefined} className={tab(companies)}>{c.companies}</Link>
      </nav>

      {res.data.length === 0 ? <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center text-ink-muted">{t.admin.common.empty}</p> : (
        <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-sand-200">
              <tr>
                {(companies ? [c.company, c.cr, c.governorate, c.members] : [c.name, c.phone, c.company, c.status]).map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-start">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-sand-300">
              {companies
                ? (res.data as CompanyRow[]).map((o) => (
                  <tr key={o.id} className="hover:bg-sand-100">
                    <td className="px-4 py-2"><Link href={`${base}/companies/${o.id}`} className="font-semibold underline-offset-4 hover:underline">{o.name}</Link></td>
                    <td className="num px-4 py-2">{o.cr_number ?? "—"}</td>
                    <td className="px-4 py-2">{t.onboarding.governorates[o.governorate as keyof AppDictionary["onboarding"]["governorates"]]}</td>
                    <td className="num px-4 py-2">{o.members_count}</td>
                  </tr>
                ))
                : (res.data as CustomerRow[]).map((u) => (
                  <tr key={u.id} className="hover:bg-sand-100">
                    <td className="px-4 py-2">
                      <Link href={`${base}/${u.id}`} className="font-semibold underline-offset-4 hover:underline">{u.full_name || c.noName}</Link>
                      {u.flagged && <span className="ms-2 text-xs text-danger">{c.flagged}</span>}
                    </td>
                    <td className="num px-4 py-2" dir="ltr">{u.phone ? formatPhone(u.phone) : "—"}</td>
                    <td className="px-4 py-2">{u.organizations.map((o) => o.name).join(lang === "ar" ? "، " : ", ") || "—"}</td>
                    <td className="px-4 py-2 font-semibold" style={{ color: CUSTOMER_COLOR[u.status] }}>{c.statuses[u.status]}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}
      {next && <Link href={`?${new URLSearchParams({ ...(companies ? { view: "companies" } : {}), cursor: next })}`} className="font-semibold underline">{c.more}</Link>}
    </>
  );
}
