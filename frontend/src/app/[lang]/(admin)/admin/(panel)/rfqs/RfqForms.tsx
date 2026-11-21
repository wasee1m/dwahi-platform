"use client";

import { useActionState, useState, useTransition } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { createCustomer, createOnBehalf, findCustomer, rfqAction, type Customer } from "../rfq-actions";

// A-05: أزرار الطلب (إسناد، إعداد عرض، خسارة، إعادة فتح)، وطلب نيابة عن عميل
type T = AppDictionary["admin"]["rfqs"];

export function RfqButtons({ lang, t, rfq, assignees, canPrepare, prepareLabel, canLose, canReopen }: {
  lang: string; t: T; rfq: { id: string; assigned_to: { id: string } | null }; assignees: { id: string; full_name: string }[];
  canPrepare: boolean; prepareLabel: string; canLose: boolean; canReopen: boolean;
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(rfqAction, {});
  const [panel, setPanel] = useState<"lose" | "reopen" | null>(null);
  const hidden = (op: string) => (
    <>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={rfq.id} />
      <input type="hidden" name="op" value={op} />
    </>
  );

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-2">
        {canPrepare && (
          <form action={action}>{hidden("draft")}<SubmitButton pending={pending} className="py-2.5">{prepareLabel}</SubmitButton></form>
        )}
        <form action={action} className="flex items-end gap-2">
          {hidden("assign")}
          <select name="user_id" defaultValue={rfq.assigned_to?.id ?? ""} aria-label={t.assignTo} className="rounded-sm border border-sand-300 bg-sand-0 px-2 py-2.5">
            <option value="">{t.assignMe}</option>
            {assignees.map((a) => <option key={a.id} value={a.id}>{a.full_name}</option>)}
          </select>
          <button disabled={pending} className="rounded-sm border border-ink px-4 py-2.5 font-semibold">{t.assign}</button>
        </form>
        {canLose && <button type="button" onClick={() => setPanel(panel === "lose" ? null : "lose")} className="rounded-sm px-4 py-2.5 font-semibold text-danger hover:bg-sand-200">{t.lose}</button>}
        {canReopen && <button type="button" onClick={() => setPanel(panel === "reopen" ? null : "reopen")} className="rounded-sm border border-ink px-4 py-2.5 font-semibold">{t.reopen}</button>}
      </div>
      {panel && (
        <form action={action} className="flex flex-wrap items-end gap-2 rounded-md border border-sand-300 bg-sand-0 p-3">
          {hidden(panel)}
          <Field label={panel === "lose" ? t.loseReason : t.reopenReason} error={state.fieldErrors?.reason?.[0]}>
            <input name="reason" required className={`${inputClass} w-80`} />
          </Field>
          <button disabled={pending} className={`rounded-sm px-4 py-2.5 font-bold ${panel === "lose" ? "bg-danger text-white" : "bg-sun text-on-sun"}`}>{panel === "lose" ? t.confirmLose : t.reopen}</button>
        </form>
      )}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
    </div>
  );
}

/** طلب نيابة عن عميل (01 §3.2): البحث برقم الجوال أو إنشاء حسابه، ثم البنود سطراً سطراً */
export function OnBehalfForm({ lang, t }: { lang: string; t: T }) {
  const [phone, setPhone] = useState("");
  const [name, setName] = useState("");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [lookedUp, setLookedUp] = useState(false);
  const [msg, setMsg] = useState("");
  const [busy, start] = useTransition();
  const [state, action, pending] = useActionState<AdminState, FormData>(createOnBehalf, {});

  const find = () => start(async () => {
    const r = await findCustomer(lang, phone);
    setCustomer(r.customer);
    setLookedUp(true);
    setMsg("");
  });
  const create = () => start(async () => {
    const r = await createCustomer(lang, phone, name);
    if (r.ok) setCustomer(r.customer); else setMsg(r.message);
  });

  return (
    <div className="space-y-5">
      <section className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-5">
        <h2 className="text-lg">{t.lookupTitle}</h2>
        <div className="flex flex-wrap items-end gap-2">
          <Field label={t.phone}><input value={phone} onChange={(e) => { setPhone(e.target.value); setLookedUp(false); setCustomer(null); }} dir="ltr" inputMode="tel" className={`${inputClass} w-56`} /></Field>
          <button type="button" onClick={find} disabled={busy || phone.trim().length < 8} className="rounded-sm bg-night px-4 py-2.5 font-bold text-on-night disabled:opacity-50">{t.find}</button>
        </div>
        {customer && <Notice tone="success">{t.pickedCustomer}: {customer.full_name} <span className="num" dir="ltr">{customer.phone}</span>{customer.needs_onboarding ? ` · ${t.pendingOnboarding}` : ""}</Notice>}
        {lookedUp && !customer && (
          <div className="space-y-2">
            <p className="text-sm text-ink-muted">{t.notFound}</p>
            <div className="flex flex-wrap items-end gap-2">
              <Field label={t.fullName}><input value={name} onChange={(e) => setName(e.target.value)} className={`${inputClass} w-64`} /></Field>
              <button type="button" onClick={create} disabled={busy || !name.trim()} className="rounded-sm border border-ink px-4 py-2.5 font-semibold">{t.createCustomer}</button>
            </div>
            {msg && <Notice tone="danger">{msg}</Notice>}
          </div>
        )}
      </section>

      {customer && (
        <form onSubmit={keepValues(action)} className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-5 md:grid-cols-2">
          <input type="hidden" name="lang" value={lang} />
          <input type="hidden" name="customer_id" value={customer.id} />
          <Field label={t.type}>
            <select name="type" className={inputClass}>{(["parts", "equipment"] as const).map((x) => <option key={x} value={x}>{t.types[x]}</option>)}</select>
          </Field>
          <Field label={t.source}>
            <select name="source" className={inputClass}>{(["phone", "whatsapp", "walk_in"] as const).map((x) => <option key={x} value={x}>{t.sources[x]}</option>)}</select>
          </Field>
          <div className="md:col-span-2">
            <Field label={t.items} hint={t.itemLine}><textarea name="items" required rows={5} placeholder={"فلتر زيت 1R-0750 | 10\nطقم جوانات مضخة | 1"} className={inputClass} /></Field>
          </div>
          <div className="md:col-span-2"><Field label={t.notes}><textarea name="notes" rows={2} className={inputClass} /></Field></div>
          {state.error && <div className="md:col-span-2"><Notice tone="danger">{state.fieldErrors ? Object.values(state.fieldErrors).flat()[0] : state.error.message}</Notice></div>}
          <div className="md:col-span-2"><SubmitButton pending={pending}>{t.create}</SubmitButton></div>
        </form>
      )}
    </div>
  );
}
