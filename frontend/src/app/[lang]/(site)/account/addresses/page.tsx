import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { Address } from "@/lib/account-types";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatPhone } from "@/lib/format";
import { archiveAddress } from "../portal-actions";
import { AddressForm } from "./AddressForm";

// C-04 العناوين (FR-ACC-04، US-08): للحساب المحدد، والحذف أرشفة (BR-17)
export const metadata = { robots: { index: false } };

export default async function AddressesPage({ params }: PageProps<"/[lang]/account/addresses">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.addresses.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <List lang={lang} t={t} />
      </Suspense>
    </div>
  );
}

async function List({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const account = await selectedAccount(await currentUser("customer", lang));
  const res = await authed<Address[]>("customer", lang, `/me/addresses?${account.query}`);
  if (!res.ok) return <Notice tone="danger">{t.auth.genericError}</Notice>;
  const a = t.addresses;
  const gov = t.onboarding.governorates;

  return (
    <>
      {res.data.length === 0 && <p className="rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-ink-muted">{a.empty}</p>}
      <ul className="space-y-3">
        {res.data.map((x) => (
          <li key={x.id} className="rounded-md border border-sand-300 bg-sand-0 p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="font-bold">{x.label} <span className="ms-1 rounded-[999px] bg-sand-200 px-2 py-0.5 text-xs font-semibold">{a.kinds[x.kind]}</span></p>
                <p className="mt-1 text-sm">{gov[x.governorate as keyof typeof gov]} · {x.wilayat}</p>
                {x.description && <p className="text-sm text-ink-muted">{x.description}</p>}
                {(x.contact_name || x.contact_phone) && (
                  <p className="text-sm text-ink-muted">{x.contact_name} {x.contact_phone && <span className="num" dir="ltr">{formatPhone(x.contact_phone)}</span>}</p>
                )}
                {x.latitude !== null && x.longitude !== null && (
                  <a href={`https://www.google.com/maps?q=${x.latitude},${x.longitude}`} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold text-sun-text underline">{a.openMap}</a>
                )}
              </div>
              <ActionButton action={archiveAddress} fields={{ lang, id: x.id }} label={a.archive} look="danger"
                confirm={{ question: a.archiveQuestion, yes: t.company.yes, no: t.company.no }} />
            </div>
            <details className="mt-3">
              <summary className="cursor-pointer text-sm font-semibold">{a.edit}</summary>
              <div className="mt-3"><AddressForm lang={lang} t={a} governorates={gov} organizationId={account.organizationId} address={x} /></div>
            </details>
          </li>
        ))}
      </ul>
      <details key={`${account.value}-${res.data.length}`} className="rounded-md border border-sand-300 bg-sand-0 p-5" open={res.data.length === 0}>
        <summary className="cursor-pointer font-bold">{a.addTitle}</summary>
        <div className="mt-4"><AddressForm lang={lang} t={a} governorates={gov} organizationId={account.organizationId} /></div>
      </details>
    </>
  );
}
