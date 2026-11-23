"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { invoiceStep } from "../order-actions";
import { staffUpload } from "../rfq-actions";

type T = AppDictionary["admin"]["finance"];
const box = "space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4";
const button = "rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night disabled:opacity-60";

function failure(state: AdminState) {
  const m = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
  return m ? <Notice tone="danger">{m}</Notice> : null;
}

function Hidden({ lang, id, op }: { lang: string; id: string; op: string }) {
  return (
    <>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value={op} />
    </>
  );
}

/** 6.2: إصدار مسودة عرض المعدة بتاريخ استحقاق */
export function IssueForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(invoiceStep, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <Hidden lang={lang} id={id} op="issue" />
      <h2 className="text-base">{t.issue}</h2>
      <Field label={t.dueDate} hint={t.dueHint}><input type="date" name="due_date" className={inputClass} /></Field>
      {failure(state)}
      <button disabled={pending} className="w-full rounded-sm bg-sun px-4 py-2.5 font-bold text-on-sun disabled:opacity-60">{t.issue}</button>
    </form>
  );
}

/** 6b.4: دفعة يدوية بمرجع وإيصال، والجزئية ممكنة (US-38) */
export function PaymentForm({ lang, t, methods, id, balance }: { lang: string; t: T; methods: Record<string, string>; id: string; balance: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(invoiceStep, {});
  const [method, setMethod] = useState("bank_transfer");
  const [proof, setProof] = useState<{ id: string; name: string } | null>(null);
  async function upload(file: File) {
    const form = new FormData();
    form.set("file", file);
    form.set("lang", lang);
    const r = await staffUpload(form);
    if (r.ok) setProof({ id: r.id, name: r.name });
  }
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <Hidden lang={lang} id={id} op="payment" />
      {proof && <input type="hidden" name="proof_id" value={proof.id} />}
      <h2 className="text-base">{t.recordPayment}</h2>
      <Field label={t.method}>
        <select name="method" value={method} onChange={(e) => setMethod(e.target.value)} className={inputClass}>
          {["bank_transfer", "cheque", "cash", "pos_card"].map((m) => <option key={m} value={m}>{methods[m]}</option>)}
        </select>
      </Field>
      <Field label={t.amount} hint={`${t.balance}: ${balance}`}><input name="amount" required inputMode="decimal" dir="ltr" defaultValue={balance} className={`${inputClass} num`} /></Field>
      <Field label={t.reference}><input name="reference_text" required={method === "bank_transfer" || method === "cheque"} maxLength={255} dir="ltr" className={inputClass} /></Field>
      <Field label={t.receivedAt}><input type="datetime-local" name="received_at" className={inputClass} /></Field>
      <div className="text-sm">
        <p className="font-semibold">{t.proof}</p>
        <input type="file" accept="image/*,application/pdf" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); }} className="mt-1 block" />
        {proof && <p className="mt-1 text-ink-muted">{proof.name}</p>}
      </div>
      {state.ok && <Notice tone="success">{t.saved}</Notice>}
      {failure(state)}
      <button disabled={pending} className={button}>{t.recordPayment}</button>
    </form>
  );
}

/** 6.5: إلغاء فاتورة صادرة بلا دفعات، بإشعار دائن بقيمتها */
export function VoidForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(invoiceStep, {});
  const [open, setOpen] = useState(false);
  if (!open) return <button type="button" onClick={() => setOpen(true)} className="text-sm font-semibold text-danger hover:underline">{t.void}</button>;
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <Hidden lang={lang} id={id} op="void" />
      <Field label={t.voidReason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>
      <p className="text-xs text-ink-muted">{t.voidHint}</p>
      {failure(state)}
      <button disabled={pending} className="rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger">{t.void}</button>
    </form>
  );
}

/** 6b.5: استرداد يدوي من دفعة، مع إشعار دائن */
export function RefundForm({ lang, t, methods, invoice, payment, max }: { lang: string; t: T; methods: Record<string, string>; invoice: string; payment: string; max: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(invoiceStep, {});
  const [open, setOpen] = useState(false);
  if (!open) return <button type="button" onClick={() => setOpen(true)} className="mt-2 text-xs font-semibold text-danger hover:underline">{t.refundPayment}</button>;
  return (
    <form action={action} onSubmit={keepValues(action)} className="mt-2 grid gap-2 sm:grid-cols-2">
      <Hidden lang={lang} id={invoice} op="refund" />
      <input type="hidden" name="payment" value={payment} />
      <Field label={t.amount} hint={`≤ ${max}`}><input name="amount" required inputMode="decimal" dir="ltr" className={`${inputClass} num`} /></Field>
      <Field label={t.method}>
        <select name="method" defaultValue="cash" className={inputClass}>
          {["cash", "bank_transfer", "pos_card"].map((m) => <option key={m} value={m}>{methods[m]}</option>)}
        </select>
      </Field>
      <Field label={t.refundReason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>
      <Field label={t.reference}><input name="reference_text" maxLength={255} dir="ltr" className={inputClass} /></Field>
      <div className="sm:col-span-2">{failure(state)}{state.ok && <Notice tone="success">{t.saved}</Notice>}</div>
      <button disabled={pending} className={`${button} sm:col-span-2`}>{t.refundPayment}</button>
    </form>
  );
}
