"use client";

import { useActionState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { convertLead, updateLead } from "../content-actions";

/** "تحويل لطلب عرض سعر" (US-43) */
export function ConvertLead({ lang, label, lead }: { lang: string; label: string; lead: { id: string; name: string; phone: string; topic: string; message: string } }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(convertLead, {});
  return (
    <form action={action} className="space-y-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={lead.id} />
      <input type="hidden" name="name" value={lead.name} />
      <input type="hidden" name="phone" value={lead.phone} />
      <input type="hidden" name="topic" value={lead.topic} />
      <input type="hidden" name="message" value={lead.message} />
      <SubmitButton pending={pending}>{label}</SubmitButton>
      {state.error && <Notice tone="danger">{state.fieldErrors ? Object.values(state.fieldErrors).flat()[0] : state.error.message}</Notice>}
    </form>
  );
}

// A-15: الحالة والإسناد وملاحظة في حفظ واحد (US-43). الملاحظة تُفرَّغ بعد الحفظ، والسجل يُحدَّث من الخادم.
type T = AppDictionary["admin"]["leads"];
const STATUSES = ["new", "contacted", "qualified", "closed"] as const;

export function LeadForm({ lang, t, lead, assignees }: {
  lang: string; t: T; lead: { id: string; status: (typeof STATUSES)[number]; assigned_to: { id: string } | null }; assignees: { id: string; full_name: string }[];
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(updateLead, {});
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  return (
    <form action={action} className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-5 md:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={lead.id} />
      <Field label={t.status} error={err("status")}>
        <select name="status" defaultValue={lead.status} className={inputClass}>{STATUSES.map((s) => <option key={s} value={s}>{t.statuses[s]}</option>)}</select>
      </Field>
      <Field label={t.assigned} error={err("assigned_to")}>
        <select name="assigned_to" defaultValue={lead.assigned_to?.id ?? ""} className={inputClass}>
          <option value="">{t.nobody}</option>
          {assignees.map((a) => <option key={a.id} value={a.id}>{a.full_name}</option>)}
        </select>
      </Field>
      <div className="md:col-span-2">
        <Field label={t.note} error={err("note")}><textarea name="note" rows={3} placeholder={t.notePlaceholder} className={inputClass} /></Field>
      </div>
      <div className="flex items-center gap-3 md:col-span-2">
        <SubmitButton pending={pending}>{t.update}</SubmitButton>
        {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      </div>
    </form>
  );
}
