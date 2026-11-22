"use client";

import { useActionState, useRef, useState } from "react";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { CompanyProfile } from "@/lib/account-types";
import type { AdminState } from "../../actions";
import { addNote, inviteToCompany, suspendCustomer, updateCompanyMember } from "../customer-actions";

type T = AppDictionary["admin"]["customers"];
const button = "rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night disabled:opacity-60";

function Failure({ state }: { state: AdminState }) {
  if (!state.error) return null;
  return <Notice tone="danger">{Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message}</Notice>;
}

export function NoteForm({ lang, t, id, subject }: { lang: string; t: T; id: string; subject: "customer" | "company" }) {
  const [body, setBody] = useState("");
  const [state, action, pending] = useActionState<AdminState, FormData>(async (prev, form) => {
    const result = await addNote(prev, form);
    if (result.ok) setBody("");
    return result;
  }, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="subject" value={subject} />
      <Field label={t.newNote} hint={t.noteHint}>
        <textarea name="body" required rows={2} maxLength={2000} value={body} onChange={(e) => setBody(e.target.value)} className={inputClass} />
      </Field>
      <Failure state={state} />
      <button disabled={pending} className={button}>{t.addNote}</button>
    </form>
  );
}

export function SuspendForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(suspendCustomer, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <Field label={t.suspendReason} hint={t.suspendHint}><input name="reason" required maxLength={500} className={inputClass} /></Field>
      <Failure state={state} />
      <button disabled={pending} className="rounded-sm px-4 py-2 text-sm font-bold text-danger ring-1 ring-danger disabled:opacity-60">{t.suspend}</button>
    </form>
  );
}

export function StaffMemberForm({ lang, t, company, member }: { lang: string; t: T; company: string; member: CompanyProfile["members"][number] }) {
  const form = useRef<HTMLFormElement>(null);
  // رفض الخادم (آخر مالك) يعيد الحقول للقيمة المحفوظة فعلاً
  const [state, action, pending] = useActionState<AdminState, FormData>(async (prev, data) => {
    const result = await updateCompanyMember(prev, data);
    if (result.error) form.current?.reset();
    return result;
  }, {});
  return (
    <form ref={form} action={action} onSubmit={keepValues(action)} className="flex flex-wrap items-center gap-2 text-sm">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={company} />
      <input type="hidden" name="member" value={member.user_id} />
      <select name="role" aria-label={t.role} defaultValue={member.role} className="rounded-sm border border-sand-300 px-2 py-1.5">
        <option value="member">{t.roles.member}</option>
        <option value="owner">{t.roles.owner}</option>
      </select>
      <label className="flex items-center gap-1.5"><input type="checkbox" name="can_approve_quotes" defaultChecked={member.can_approve_quotes} /> {t.canApprove}</label>
      <button disabled={pending} className="rounded-sm border border-ink px-3 py-1.5 font-semibold">{t.save}</button>
      <Failure state={state} />
    </form>
  );
}

export function StaffInviteForm({ lang, t, company }: { lang: string; t: T; company: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(inviteToCompany, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="flex flex-wrap items-end gap-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={company} />
      <Field label={t.invitePhone}><input name="phone" type="tel" dir="ltr" required className={inputClass} /></Field>
      <Field label={t.role}>
        <select name="role" defaultValue="member" className={inputClass}>
          <option value="member">{t.roles.member}</option>
          <option value="owner">{t.roles.owner}</option>
        </select>
      </Field>
      <label className="flex items-center gap-1.5 pb-3 text-sm"><input type="checkbox" name="can_approve_quotes" /> {t.canApprove}</label>
      <button disabled={pending} className={`${button} mb-1`}>{t.invite}</button>
      <Failure state={state} />
    </form>
  );
}
