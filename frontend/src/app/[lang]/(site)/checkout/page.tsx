import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { fill, getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { selectedAccount } from "@/lib/auth/account";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { CheckoutPreview } from "@/lib/order-types";
import { PlaceOrderForm } from "./PlaceOrderForm";

// C-11 إتمام الطلب (FR-ORD-02، US-14): الخطوة 1 التوصيل أو الاستلام بنموذج GET يعيد حساب الملخص،
// والخطوة 2 المراجعة والتأكيد. الدفع عند الاستلام أو التسليم (BR-18).
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/checkout">;
type Address = { id: string; label: string; governorate: string; wilayat: string; kind: string };

export default async function CheckoutPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <Container className="py-10">
      <h1 className="text-3xl">{t.checkout.title}</h1>
      <Suspense fallback={<Skeleton className="mt-6 h-96" />}>
        <Body lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}

async function Body({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const sp = await searchParams;
  const one = (k: string) => (typeof sp[k] === "string" ? (sp[k] as string) : "");
  const me = await currentUser("customer", lang);
  const account = await selectedAccount(me);
  const addressesRes = await authed<Address[]>("customer", lang, `/me/addresses?kind=delivery&${account.query || "account=personal"}`);
  const addresses = addressesRes.ok ? addressesRes.data : [];
  const fulfillment = one("fulfillment") === "delivery" ? "delivery" : one("fulfillment") === "pickup" ? "pickup" : "";
  const qs = new URLSearchParams({ fulfillment, branch_id: one("branch_id"), address_id: one("address_id") });
  if (account.organizationId) qs.set("organization_id", account.organizationId);
  const res = await authed<CheckoutPreview>("customer", lang, `/me/checkout?${qs.toString()}`);
  if (!res.ok) return <p role="alert" className="mt-6 text-danger">{res.error.message}</p>;
  const p = res.data;
  if (p.cart.items.length === 0) redirect(`/${lang}/cart`);
  if (!p.cart.can_checkout) redirect(`/${lang}/cart`);
  const c = t.checkout;
  const governorates = t.onboarding.governorates as Record<string, string>;
  const ready = !!fulfillment && !p.problem;
  const companyName = account.organizationId ? me.organizations.find((o) => o.id === account.organizationId)?.name : null;

  return (
    <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="space-y-6">
        <p className="text-sm text-ink-muted">{c.account}: <b className="text-ink">{companyName ?? t.account.personal}</b></p>

        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <h2 className="text-lg">{c.step1}</h2>
          <form method="get" className="mt-4 space-y-4">
            <fieldset className="space-y-3">
              <legend className="sr-only">{c.step1}</legend>
              <label className="flex items-start gap-3 rounded-sm border border-sand-300 p-3">
                <input type="radio" name="fulfillment" value="pickup" defaultChecked={fulfillment !== "delivery"} className="mt-1 size-4" />
                <span className="flex-1">
                  <b>{c.pickup}</b>
                  <select name="branch_id" defaultValue={one("branch_id") || p.branches.find((b) => b.can_fulfill)?.id || ""} aria-label={c.chooseBranch}
                    className="mt-2 block w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2">
                    {p.branches.map((b) => (
                      <option key={b.id} value={b.id}>{b.name}{b.can_fulfill ? "" : ` (${c.branchCannot})`}</option>
                    ))}
                  </select>
                </span>
              </label>
              <label className="flex items-start gap-3 rounded-sm border border-sand-300 p-3">
                <input type="radio" name="fulfillment" value="delivery" defaultChecked={fulfillment === "delivery"} className="mt-1 size-4" />
                <span className="flex-1">
                  <b>{c.delivery}</b>
                  {addresses.length === 0 ? (
                    <span className="mt-2 block text-sm text-ink-muted">
                      {c.noAddresses} <Link href={`/${lang}/account/addresses`} className="font-semibold text-sun-text underline">{c.addAddress}</Link>
                    </span>
                  ) : (
                    <select name="address_id" defaultValue={one("address_id") || addresses[0].id} aria-label={c.chooseAddress}
                      className="mt-2 block w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2">
                      {addresses.map((a) => {
                        const fee = p.delivery_fees[a.governorate];
                        return (
                          <option key={a.id} value={a.id}>
                            {a.label} · {governorates[a.governorate] ?? a.governorate} · {fee ? `${c.fee} ${formatMoney(fee, lang)}` : c.noDelivery}
                          </option>
                        );
                      })}
                    </select>
                  )}
                </span>
              </label>
            </fieldset>
            <button className="rounded-sm border border-ink px-4 py-2 text-sm font-semibold hover:bg-sand-200">{c.apply}</button>
          </form>
          {fulfillment && p.problem && <p role="alert" className="mt-3 font-semibold text-danger">{c.problems[p.problem as keyof typeof c.problems] ?? p.problem}</p>}
        </section>

        <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <h2 className="text-lg">{c.step2}</h2>
          <ul className="mt-3 divide-y divide-sand-300 text-sm">
            {p.cart.items.map((i) => (
              <li key={i.part.id} className="flex justify-between gap-3 py-2">
                <span className="min-w-0"><span className="num" dir="ltr">{i.part.part_number}</span> · {i.part.name} × <span className="num">{Number(i.quantity)}</span></span>
                <span className="num shrink-0">{i.line_subtotal && formatMoney(i.line_subtotal, lang)}</span>
              </li>
            ))}
          </ul>
          <div className="mt-4 rounded-sm bg-sand-100 p-4 text-sm">
            <p className="font-bold">{c.payment}</p>
            <p className="mt-1 text-ink-muted">{c.paymentText}</p>
          </div>
          {p.needs_phone_confirmation && <p role="status" className="mt-3 text-sm font-semibold text-[var(--status-waiting)]">{c.phoneNote}</p>}
          {p.open_orders.blocked && (
            <p role="alert" className="mt-3 text-sm font-semibold text-danger">{fill(c.limitReached, { count: String(p.open_orders.count) })}</p>
          )}
        </section>
      </div>

      <aside className="self-start rounded-md border border-sand-300 bg-sand-0 p-5">
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt>{t.cart.subtotal}</dt><dd className="num">{p.totals.subtotal && formatMoney(p.totals.subtotal, lang)}</dd></div>
          {fulfillment === "delivery" && (
            <div className="flex justify-between"><dt>{c.fee}</dt><dd className="num">{p.totals.delivery_fee && formatMoney(p.totals.delivery_fee, lang)}</dd></div>
          )}
          <div className="flex justify-between"><dt>{t.cart.vat}</dt><dd className="num">{p.totals.vat_total && formatMoney(p.totals.vat_total, lang)}</dd></div>
          <div className="flex justify-between border-t border-sand-300 pt-2 text-base font-bold"><dt>{t.cart.total}</dt><dd className="num">{p.totals.total && formatMoney(p.totals.total, lang)}</dd></div>
        </dl>
        {ready && !p.open_orders.blocked ? (
          <PlaceOrderForm lang={lang} t={c} fulfillment={fulfillment} branchId={p.branch_id ?? ""} addressId={one("address_id") || addresses[0]?.id || ""} />
        ) : (
          <p className="mt-4 rounded-sm bg-sand-200 px-4 py-3 text-center text-sm font-semibold text-ink-muted">{fulfillment ? c.fixFirst : c.chooseFirst}</p>
        )}
        <Link href={`/${lang}/cart`} className="mt-3 block text-center text-sm font-semibold text-ink-muted hover:underline">{c.backToCart}</Link>
      </aside>
    </div>
  );
}
