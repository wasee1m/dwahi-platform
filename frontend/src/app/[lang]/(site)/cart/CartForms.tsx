"use client";

import { useActionState } from "react";
import { keepValues } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { acknowledgeCart, addToCart, setCartQuantity, type OrderState } from "../order-actions";

type T = AppDictionary["cart"];

export function QuantityForm({ lang, t, part, quantity }: { lang: string; t: T; part: string; quantity: string }) {
  const [state, action, pending] = useActionState<OrderState, FormData>(setCartQuantity, {});
  const message = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
  return (
    <div className="flex flex-col items-end gap-1">
      <form action={action} onSubmit={keepValues(action)} className="flex items-center gap-2">
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="part" value={part} />
        <label className="sr-only" htmlFor={`q-${part}`}>{t.quantity}</label>
        <input id={`q-${part}`} name="quantity" type="number" min={1} max={999} step={1} defaultValue={Number(quantity)} dir="ltr"
          className="num w-20 rounded-sm border border-sand-300 bg-sand-100 px-2 py-1.5 text-center" />
        <button disabled={pending} className="rounded-sm border border-ink px-3 py-1.5 text-sm font-semibold disabled:opacity-60">{t.update}</button>
      </form>
      <form action={action}>
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="part" value={part} />
        <input type="hidden" name="quantity" value="0" />
        <button disabled={pending} className="text-sm font-semibold text-danger hover:underline">{t.remove}</button>
      </form>
      {message && <span role="alert" className="text-sm text-danger">{message}</span>}
    </div>
  );
}

export function AcknowledgeButton({ lang, label }: { lang: string; label: string }) {
  const [, action, pending] = useActionState<OrderState, FormData>(acknowledgeCart, {});
  return (
    <form action={action}>
      <input type="hidden" name="lang" value={lang} />
      <button disabled={pending} className="rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night disabled:opacity-60">{label}</button>
    </form>
  );
}

/** W-05 "أضف للسلة" بكمية. الزائر يُرسل للدخول ثم يعود لنفس القطعة. */
export function AddToCart({ lang, part, back, labels }: {
  lang: string; part: string; back: string;
  labels: { add: string; added: string; viewCart: string; quantity: string };
}) {
  const [state, action, pending] = useActionState<OrderState, FormData>(addToCart, {});
  const message = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
  return (
    <form action={action} onSubmit={keepValues(action)} className="flex flex-wrap items-center gap-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="part" value={part} />
      <input type="hidden" name="back" value={back} />
      <label className="sr-only" htmlFor="cart-qty">{labels.quantity}</label>
      <input id="cart-qty" name="quantity" type="number" min={1} max={999} step={1} defaultValue={1} dir="ltr"
        className="num w-20 rounded-sm border border-sand-300 bg-sand-0 px-2 py-2.5 text-center" />
      <button disabled={pending} className="rounded-sm bg-sun px-6 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{labels.add}</button>
      {state.ok && (
        <span role="status" className="text-sm font-semibold text-success">
          {labels.added} <a href={`/${lang}/cart`} className="underline">{labels.viewCart}</a>
        </span>
      )}
      {message && <span role="alert" className="text-sm text-danger">{message}</span>}
    </form>
  );
}
