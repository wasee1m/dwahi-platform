"use client";

import { useActionState, useState } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { deliverOrder, orderStep, returnStep } from "../order-actions";
import { staffUpload } from "../rfq-actions";

type T = AppDictionary["admin"]["orders"];
const button = "rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night disabled:opacity-60";

function message(state: AdminState) {
  return state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
}

export function Step({ lang, id, op, label, look = "plain" }: { lang: string; id: string; op: string; label: string; look?: "plain" | "primary" | "danger" }) {
  return <ActionButton action={orderStep} fields={{ lang, id, op }} label={label} look={look} />;
}

/** الإلغاء أو تعذّر التسليم: السبب إلزامي (3.7، 3.10) */
export function ReasonStep({ lang, t, id, op, label, danger }: { lang: string; t: T; id: string; op: string; label: string; danger?: boolean }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(orderStep, {});
  const [open, setOpen] = useState(false);
  if (!open) {
    return <button type="button" onClick={() => setOpen(true)} className={`text-sm font-semibold hover:underline ${danger ? "text-danger" : ""}`}>{label}</button>;
  }
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-2 rounded-md border border-sand-300 bg-sand-0 p-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value={op} />
      <Field label={t.reason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>
      {message(state) && <Notice tone="danger">{message(state)}</Notice>}
      <div className="flex gap-2">
        <button disabled={pending} className={danger ? "rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger" : button}>{label}</button>
        <button type="button" onClick={() => setOpen(false)} className="px-3 py-2 text-sm text-ink-muted">{t.back}</button>
      </div>
    </form>
  );
}

/** BR-18: نتيجة الاتصال قبل التجهيز. "تعذّر" يلغي الطلب بالسبب. */
export function PhoneForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(orderStep, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value="phone" />
      <p className="font-semibold">{t.phoneTitle}</p>
      <Field label={t.phoneNote}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>
      <div className="flex flex-wrap gap-2">
        <button name="confirmed" value="1" disabled={pending} className={button}>{t.phoneConfirmed}</button>
        <button name="confirmed" value="0" disabled={pending} className="rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger">{t.phoneFailed}</button>
      </div>
      {message(state) && <Notice tone="danger">{message(state)}</Notice>}
    </form>
  );
}

/** 3.11 و 3.12: رمز الاستلام أو صورة الإثبات، والدفعة بالمتبقي كاملاً في نفس الخطوة */
export function DeliverForm({ lang, t, methods, id, pickup, balance }: {
  lang: string; t: T; methods: Record<string, string>; id: string; pickup: boolean; balance: string;
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(deliverOrder, {});
  const [method, setMethod] = useState("cash");
  const [proof, setProof] = useState<{ id: string; name: string } | null>(null);
  const [uploading, setUploading] = useState(false);
  const due = Number(balance) > 0;
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  async function upload(file: File) {
    setUploading(true);
    const form = new FormData();
    form.set("file", file);
    form.set("lang", lang);
    const r = await staffUpload(form);
    setUploading(false);
    if (r.ok) setProof({ id: r.id, name: r.name });
  }

  if (state.ok) return <Notice tone="success">{t.delivered}</Notice>;

  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3 rounded-md border-2 border-sun bg-sand-0 p-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      {proof && <input type="hidden" name="proof_id" value={proof.id} />}
      <h2 className="text-base">{t.deliverTitle}</h2>
      {pickup ? (
        <Field label={t.code} error={err("code")}>
          <input name="code" required inputMode="numeric" pattern="\d{6}" maxLength={6} dir="ltr" autoComplete="off" className={`${inputClass} num text-center text-xl tracking-[0.3em]`} />
        </Field>
      ) : (
        <div className="text-sm">
          <p className="font-semibold">{t.proof}</p>
          <input type="file" accept="image/*" capture="environment" disabled={uploading}
            onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); }} className="mt-1 block" />
          {proof && <p className="mt-1 text-ink-muted">{proof.name}</p>}
          {err("proof_id") && <p role="alert" className="text-danger">{err("proof_id")}</p>}
        </div>
      )}
      {due ? (
        <>
          <Field label={t.method}>
            <select name="method" value={method} onChange={(e) => setMethod(e.target.value)} className={inputClass}>
              {["cash", "pos_card", "bank_transfer"].map((m) => <option key={m} value={m}>{methods[m]}</option>)}
            </select>
          </Field>
          <Field label={t.amount} hint={t.amountHint}>
            <input name="amount" required readOnly value={balance} dir="ltr" className={`${inputClass} num bg-sand-200`} />
          </Field>
          {method !== "cash" && (
            <Field label={t.reference} error={err("payment.reference_text")}>
              <input name="reference_text" required maxLength={255} dir="ltr" className={inputClass} />
            </Field>
          )}
        </>
      ) : <p className="text-sm text-ink-muted">{t.alreadyPaid}</p>}
      {message(state) && !err("code") && <Notice tone="danger">{message(state)}</Notice>}
      <button disabled={pending || uploading} className="w-full rounded-sm bg-sun px-4 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">
        {due ? t.deliverBtn : t.deliverOnly}
      </button>
    </form>
  );
}

/** SM-3b: قرار الموظف، والاستلام مع تحديد الصالح للمخزون، والاسترداد للمحاسب */
export function ReturnActions({ lang, t, id, status, items, canDecide, canRefund }: {
  lang: string; t: T; id: string; status: string; items: { order_item_id: string; name: string; quantity: string }[];
  canDecide: boolean; canRefund: boolean;
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(returnStep, {});
  if (state.ok) return <Notice tone="success">{t.saved}</Notice>;
  const base = (
    <>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
    </>
  );
  return (
    <div className="space-y-2">
      {status === "requested" && canDecide && (
        <form action={action} onSubmit={keepValues(action)} className="space-y-2">
          {base}
          <Field label={t.decisionNote}><input name="note" maxLength={1000} className={inputClass} /></Field>
          <div className="flex flex-wrap gap-2">
            <button name="op" value="approve" disabled={pending} className={button}>{t.approve}</button>
            <button name="op" value="reject" disabled={pending} className="rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger">{t.reject}</button>
          </div>
        </form>
      )}
      {status === "approved" && canDecide && (
        <form action={action} className="space-y-2">
          {base}
          <input type="hidden" name="op" value="receive" />
          {items.map((i) => (
            <label key={i.order_item_id} className="flex items-center gap-2 text-sm">
              <input type="hidden" name="item" value={i.order_item_id} />
              <input type="checkbox" name={`restock_${i.order_item_id}`} defaultChecked className="size-4" />
              {i.name} × <span className="num">{Number(i.quantity)}</span> · {t.restock}
            </label>
          ))}
          <button disabled={pending} className={button}>{t.receive}</button>
        </form>
      )}
      {status === "received" && canRefund && (
        <form action={action} onSubmit={keepValues(action)} className="flex flex-wrap items-end gap-2">
          {base}
          <input type="hidden" name="op" value="refund" />
          <Field label={t.method}>
            <select name="method" defaultValue="cash" className={inputClass}>
              <option value="cash">{t.methods.cash}</option>
              <option value="bank_transfer">{t.methods.bank_transfer}</option>
              <option value="pos_card">{t.methods.pos_card}</option>
            </select>
          </Field>
          <Field label={t.reference}><input name="reference_text" maxLength={255} dir="ltr" className={inputClass} /></Field>
          <button disabled={pending} className={`${button} mb-1`}>{t.refund}</button>
        </form>
      )}
      {message(state) && <Notice tone="danger">{message(state)}</Notice>}
    </div>
  );
}
