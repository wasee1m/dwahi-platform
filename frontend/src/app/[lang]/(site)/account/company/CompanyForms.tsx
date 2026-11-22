"use client";

import { useActionState, useRef } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Company } from "@/lib/account-types";
import { createCompany, inviteMember, updateCompany, updateMember, type PortalState } from "../portal-actions";

type T = AppDictionary["company"];
type Names = AppDictionary["onboarding"]["governorates"];

export function CompanyForm({ lang, t, governorates, company }: { lang: string; t: T; governorates: Names; company?: Company }) {
  const [state, action, pending] = useActionState<PortalState, FormData>(company ? updateCompany : createCompany, {});
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  return (
    <form action={action} onSubmit={keepValues(action)} className="grid gap-4 sm:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      {company && <input type="hidden" name="company" value={company.id} />}
      <Field label={t.name} error={err("name")}><input name="name" required maxLength={190} defaultValue={company?.name} className={inputClass} /></Field>
      <Field label={t.governorate} error={err("governorate")}>
        <select name="governorate" required defaultValue={company?.governorate ?? ""} className={inputClass}>
          <option value="" disabled>—</option>
          {Object.entries(governorates).map(([k, name]) => <option key={k} value={k}>{name}</option>)}
        </select>
      </Field>
      <Field label={t.cr} error={err("cr_number")}><input name="cr_number" dir="ltr" maxLength={50} defaultValue={company?.cr_number ?? ""} className={inputClass} /></Field>
      <Field label={t.vat} error={err("vat_number")}><input name="vat_number" dir="ltr" maxLength={50} defaultValue={company?.vat_number ?? ""} className={inputClass} /></Field>
      {company && (
        <div className="sm:col-span-2">
          <Field label={t.preapproval} hint={t.preapprovalHint} error={err("preapproval_limit")}>
            <input name="preapproval_limit" inputMode="decimal" dir="ltr" defaultValue={company.preapproval_limit.amount} className={`${inputClass} num max-w-40`} />
          </Field>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
        <SubmitButton pending={pending}>{company ? t.save : t.create}</SubmitButton>
        {state.ok && <span role="status" className="text-sm font-semibold text-success">{t.saved}</span>}
        {state.error && !state.fieldErrors && <span role="alert" className="text-sm text-danger">{state.error.message}</span>}
      </div>
    </form>
  );
}

export function MemberForm({ lang, t, company, member }: { lang: string; t: T; company: string; member: Company["members"][number] }) {
  const form = useRef<HTMLFormElement>(null);
  // رفض الخادم يعيد الحقول للقيمة المحفوظة فعلاً، لا لما اختاره المستخدم
  const [state, action, pending] = useActionState<PortalState, FormData>(async (prev, data) => {
    const result = await updateMember(prev, data);
    if (result.error) form.current?.reset();
    return result;
  }, {});
  return (
    <form ref={form} action={action} onSubmit={keepValues(action)} className="flex flex-wrap items-center gap-2 text-sm">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="company" value={company} />
      <input type="hidden" name="member" value={member.user_id} />
      <select name="role" aria-label={t.role} defaultValue={member.role} className="rounded-sm border border-sand-300 bg-sand-100 px-2 py-1.5">
        <option value="member">{t.roles.member}</option>
        <option value="owner">{t.roles.owner}</option>
      </select>
      <label className="flex items-center gap-1.5">
        <input type="checkbox" name="can_approve_quotes" defaultChecked={member.can_approve_quotes} className="size-4" /> {t.canApprove}
      </label>
      <button disabled={pending} className="rounded-sm border border-ink px-3 py-1.5 font-semibold">{t.save}</button>
      {state.ok && <span role="status" className="text-success">{t.saved}</span>}
      {state.error && <span role="alert" className="text-danger">{state.error.message}</span>}
    </form>
  );
}

export function InviteForm({ lang, t, company }: { lang: string; t: T; company: string }) {
  const [state, action, pending] = useActionState<PortalState, FormData>(inviteMember, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="company" value={company} />
      <h3 className="font-bold">{t.invite}</h3>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t.phone} hint={t.inviteHint} error={state.fieldErrors?.phone?.[0]}>
          <input name="phone" type="tel" dir="ltr" required placeholder="9XXX XXXX" className={inputClass} />
        </Field>
        <Field label={t.role}>
          <select name="role" defaultValue="member" className={inputClass}>
            <option value="member">{t.roles.member}</option>
            <option value="owner">{t.roles.owner}</option>
          </select>
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="can_approve_quotes" className="size-4" /> {t.canApprove}</label>
      {state.ok && <Notice tone="success">{t.invited}</Notice>}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <SubmitButton pending={pending}>{t.sendInvite}</SubmitButton>
    </form>
  );
}
