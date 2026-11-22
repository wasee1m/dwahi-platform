import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import type { Address, FleetItem } from "@/lib/account-types";
import { getEquipment } from "@/lib/api";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { RfqForm } from "./RfqForm";

// C-07 طلب عرض سعر جديد. يُفتح من صفحة المعدة (?type=equipment&listing=slug) أو القطعة (?type=parts&part=id&label=)
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/quotes/new">;

export default async function NewRfqPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="max-w-4xl space-y-6">
      <Link href={`/${lang}/account/quotes`} className="text-sm font-semibold text-ink-muted">← {t.quotes.title}</Link>
      <h1 className="text-3xl">{t.quotes.new}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Form lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Form({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const one = (k: string) => (typeof sp[k] === "string" ? (sp[k] as string) : undefined);
  let initial: { listing_id?: string; part_id?: string; label: string } | undefined;

  const slug = one("listing");
  if (slug) {
    const listing = await getEquipment(lang, slug);
    if (listing) initial = { listing_id: listing.id, label: listing.title };
  }
  const part = one("part");
  if (part && /^[0-9a-f-]{36}$/i.test(part)) initial = { part_id: part, label: (one("label") ?? "").slice(0, 200) };

  const type = one("type") === "equipment" || initial?.listing_id ? "equipment" : "parts";
  const me = await currentUser("customer", lang);
  const [selected, addresses, fleet] = await Promise.all([
    selectedAccount(me),
    authed<Address[]>("customer", lang, "/me/addresses?kind=delivery"),
    authed<FleetItem[]>("customer", lang, "/me/fleet"),
  ]);
  const machines = fleet.ok ? fleet.data : [];
  const wanted = one("equipment");
  // المعدة قد تكون خارج الصفحة الأولى من الأسطول
  if (wanted && /^[0-9a-f-]{36}$/i.test(wanted) && !machines.some((m) => m.id === wanted)) {
    const extra = await authed<FleetItem>("customer", lang, `/me/fleet/${wanted}`);
    if (extra.ok) machines.push(extra.data);
  }
  const machine = machines.find((m) => m.id === wanted);
  return (
    <RfqForm lang={lang} t={t.quotes} initialType={type} initial={initial}
      accounts={me.organizations.map((o) => ({ id: o.id, name: o.name }))} addresses={addresses.ok ? addresses.data : []} fleet={machines}
      initialAccount={machine ? (machine.account?.id ?? "personal") : selected.value} initialEquipment={machine?.id ?? ""} />
  );
}
