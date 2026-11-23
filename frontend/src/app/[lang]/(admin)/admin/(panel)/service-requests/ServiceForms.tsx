"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { serviceStep } from "../service-actions";

type T = AppDictionary["admin"]["service"];
const box = "space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4";
const button = "rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night disabled:opacity-60";

function failure(state: AdminState) {
  const m = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);
  return m ? <Notice tone="danger">{m}</Notice> : null;
}

/** 4.2: الأولوية والفرع والمسار. "فحص" ينشئ أمر عمل فحص بتعليماته. */
export function TriageForm({ lang, t, id, urgency, branchId, branches }: {
  lang: string; t: T; id: string; urgency: string; branchId: string; branches: { id: string; name: string }[];
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(serviceStep, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value="triage" />
      <h2 className="text-base">{t.triage}</h2>
      <Field label={t.route}>
        <select name="route" defaultValue="inspection" className={inputClass}>
          <option value="inspection">{t.routes.inspection}</option>
          <option value="direct_estimate">{t.routes.direct_estimate}</option>
        </select>
      </Field>
      <Field label={t.priority}>
        <select name="urgency" defaultValue={urgency} className={inputClass}>
          <option value="normal">{t.priorities.normal}</option>
          <option value="urgent">{t.priorities.urgent}</option>
        </select>
      </Field>
      <Field label={t.branch}>
        <select name="branch_id" defaultValue={branchId} className={inputClass}>
          {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </Field>
      <Field label={t.instructions} hint={t.instructionsHint}><textarea name="instructions" rows={2} maxLength={2000} className={inputClass} /></Field>
      {failure(state)}
      <button disabled={pending} className={button}>{t.saveTriage}</button>
    </form>
  );
}

/** 4.3: الرفض بسبب إلزامي (N-25) */
export function DeclineForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(serviceStep, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value="decline" />
      <Field label={t.declineReason}><input name="reason" required minLength={3} maxLength={1000} className={inputClass} /></Field>
      {failure(state)}
      <button disabled={pending} className="rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger">{t.decline}</button>
    </form>
  );
}

/** 4.11: أمر الإصلاح بعد اعتماد التقدير */
export function RepairForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(serviceStep, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className={box}>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value="repair" />
      <h2 className="text-base">{t.repairOrder}</h2>
      <Field label={t.instructions}><textarea name="instructions" rows={2} maxLength={2000} className={inputClass} /></Field>
      {failure(state)}
      <button disabled={pending} className={button}>{t.createRepair}</button>
    </form>
  );
}
