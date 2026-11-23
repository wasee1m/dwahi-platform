"use client";

import { useActionState } from "react";
import { keepValues } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { placeOrder, type OrderState } from "../order-actions";

export function PlaceOrderForm({ lang, t, fulfillment, branchId, addressId }: {
  lang: string; t: AppDictionary["checkout"]; fulfillment: string; branchId: string; addressId: string;
}) {
  const [state, action, pending] = useActionState<OrderState, FormData>(placeOrder, {});
  const message = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
  return (
    <form action={action} onSubmit={keepValues(action)} className="mt-4 space-y-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="fulfillment" value={fulfillment} />
      <input type="hidden" name="branch_id" value={branchId} />
      <input type="hidden" name="address_id" value={addressId} />
      <label className="block text-sm">
        <span className="font-semibold">{t.notes}</span>
        <textarea name="notes" rows={2} maxLength={1000} className="mt-1 block w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2" />
      </label>
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" name="accept_returns_policy" required className="mt-0.5 size-4" />
        <span>{t.acceptPolicy} (<a href={`/${lang}/returns`} target="_blank" className="font-semibold underline">{t.policyLink}</a>)</span>
      </label>
      {message && <p role="alert" className="text-sm font-semibold text-danger">{message}</p>}
      <button disabled={pending} className="w-full rounded-sm bg-sun px-5 py-3 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{t.confirm}</button>
    </form>
  );
}
