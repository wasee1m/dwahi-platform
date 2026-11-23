import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Cart } from "@/lib/order-types";
import { AcknowledgeButton, QuantityForm } from "./CartForms";

// C-10 السلة (FR-ORD-01، US-14): السعر والتوفر يُفحصان عند كل فتح، وتغيّر السعر يحتاج موافقة قبل الإتمام
export const metadata = { robots: { index: false } };

export default async function CartPage({ params }: PageProps<"/[lang]/cart">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <Container className="py-10">
      <h1 className="text-3xl">{t.cart.title}</h1>
      <Suspense fallback={<Skeleton className="mt-6 h-80" />}>
        <CartBody lang={lang} t={t} />
      </Suspense>
    </Container>
  );
}

async function CartBody({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const res = await authed<Cart>("customer", lang, "/me/cart");
  if (!res.ok) return <p role="alert" className="mt-6 text-danger">{res.error.message}</p>;
  const cart = res.data;
  const c = t.cart;

  if (cart.items.length === 0) {
    return (
      <div className="mt-6 rounded-md border border-dashed border-sand-300 bg-sand-0 p-8 text-center">
        <p className="text-ink-muted">{c.empty}</p>
        <Link href={`/${lang}/parts`} className="mt-4 inline-block rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep">{c.findParts}</Link>
      </div>
    );
  }

  return (
    <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
      <div className="space-y-4">
        {cart.has_changes && (
          <div role="status" className="rounded-md border border-[var(--status-waiting)] bg-sand-0 p-4">
            <p className="font-semibold">{c.changesNote}</p>
            {cart.items.some((i) => i.previous_price) && <div className="mt-3"><AcknowledgeButton lang={lang} label={c.acceptPrices} /></div>}
          </div>
        )}
        <ul className="divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
          {cart.items.map((i) => (
            <li key={i.part.id} className="flex flex-wrap items-start justify-between gap-4 p-4">
              <div className="min-w-0 flex-1">
                <p className="font-bold"><span className="num" dir="ltr">{i.part.part_number}</span> · {i.part.brand}</p>
                <p className="text-sm">{i.part.name}</p>
                <p className="mt-1 text-sm text-ink-muted">
                  {c.unitPrice}: <span className="num">{i.unit_price && formatMoney(i.unit_price, lang)}</span>
                  {i.previous_price && i.unit_price && (
                    <span className="ms-2 font-semibold text-[var(--status-waiting)]">
                      {c.priceChanged.replace("{old}", formatMoney(i.previous_price, lang)).replace("{new}", formatMoney(i.unit_price, lang))}
                    </span>
                  )}
                </p>
                {i.problem && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{c.problems[i.problem]}</p>}
              </div>
              <div className="flex flex-col items-end gap-2">
                <QuantityForm lang={lang} t={c} part={i.part.id} quantity={i.quantity} />
                <p className="num font-bold">{i.line_subtotal && formatMoney(i.line_subtotal, lang)}</p>
              </div>
            </li>
          ))}
        </ul>
        <p className="text-xs text-ink-muted">{c.vatNote}</p>
      </div>
      <aside className="self-start rounded-md border border-sand-300 bg-sand-0 p-5">
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between"><dt>{c.subtotal}</dt><dd className="num">{cart.totals.subtotal && formatMoney(cart.totals.subtotal, lang)}</dd></div>
          <div className="flex justify-between"><dt>{c.vat}</dt><dd className="num">{cart.totals.vat_total && formatMoney(cart.totals.vat_total, lang)}</dd></div>
          <div className="flex justify-between border-t border-sand-300 pt-2 text-base font-bold"><dt>{c.total}</dt><dd className="num">{cart.totals.total && formatMoney(cart.totals.total, lang)}</dd></div>
        </dl>
        <p className="mt-2 text-xs text-ink-muted">{c.feesLater}</p>
        {cart.can_checkout ? (
          <Link href={`/${lang}/checkout`} className="mt-4 block rounded-sm bg-sun px-5 py-3 text-center font-bold text-on-sun hover:bg-sun-deep">{c.checkout}</Link>
        ) : (
          <p className="mt-4 rounded-sm bg-sand-200 px-4 py-3 text-center text-sm font-semibold text-ink-muted">{c.fixFirst}</p>
        )}
      </aside>
    </div>
  );
}
