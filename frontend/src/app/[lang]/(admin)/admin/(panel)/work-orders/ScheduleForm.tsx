"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { cancelWorkOrder, scheduleWorkOrder } from "../service-actions";
import { listSep } from "@/lib/format";

type T = AppDictionary["admin"]["service"];
type Tech = { id: string; name: string; skills: string[] };
type State = AdminState & { conflicts?: { work_order: string; technician: string }[] };

/** 5.2 و 5.3: فني رئيسي ومساعدون ونافذة. التعارض يُعرض ويُؤكَّد بضغطة ثانية، وإعادة الجدولة بسبب. */
export function ScheduleForm({ lang, t, id, technicians, rescheduling, initial }: {
  lang: string; t: T; id: string; technicians: Tech[]; rescheduling: boolean;
  initial: { lead: string; assistants: string[]; start: string; hours: number };
}) {
  const [state, action, pending] = useActionState<State, FormData>(scheduleWorkOrder, {});
  const [lead, setLead] = useState(initial.lead || technicians[0]?.id || "");
  const conflict = state.error?.code === "SCHEDULE_CONFLICT";
  const message = state.error && !conflict ? Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message : null;

  if (state.ok) return <Notice tone="success">{t.scheduled}</Notice>;

  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3 rounded-md border-2 border-sun bg-sand-0 p-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <h2 className="text-base">{rescheduling ? t.reschedule : t.schedule}</h2>
      <Field label={t.lead}>
        <select name="lead_id" value={lead} onChange={(e) => setLead(e.target.value)} className={inputClass}>
          {technicians.map((x) => <option key={x.id} value={x.id}>{x.name}{x.skills.length ? ` · ${x.skills.join(listSep(lang))}` : ""}</option>)}
        </select>
      </Field>
      <fieldset className="text-sm">
        <legend className="font-semibold">{t.assistants}</legend>
        <div className="mt-1 grid gap-1 sm:grid-cols-2">
          {technicians.filter((x) => x.id !== lead).map((x) => (
            <label key={x.id} className="flex items-center gap-2">
              <input type="checkbox" name="assistant_ids" value={x.id} defaultChecked={initial.assistants.includes(x.id)} className="size-4" /> {x.name}
            </label>
          ))}
        </div>
      </fieldset>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t.start}><input type="datetime-local" name="start" required defaultValue={initial.start} className={inputClass} /></Field>
        <Field label={t.hours}><input type="number" name="hours" min={1} max={72} defaultValue={initial.hours} className={inputClass} /></Field>
      </div>
      {rescheduling && <Field label={t.rescheduleReason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>}
      {conflict && (
        <div role="alert" className="rounded-sm border border-[var(--status-waiting)] p-3 text-sm">
          <p className="font-semibold">{state.error?.message}</p>
          <ul className="mt-1 list-inside list-disc">{state.conflicts?.map((c, n) => <li key={n}>{c.technician} · <span className="num" dir="ltr">{c.work_order}</span></li>)}</ul>
          <input type="hidden" name="force" value="1" />
        </div>
      )}
      {message && <Notice tone="danger">{message}</Notice>}
      <button disabled={pending} className="w-full rounded-sm bg-sun px-4 py-2.5 font-bold text-on-sun disabled:opacity-60">{conflict ? t.confirmAnyway : t.save}</button>
    </form>
  );
}

/** 5.14: الإلغاء بسبب (N-29) */
export function CancelWorkOrder({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(cancelWorkOrder, {});
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-2 rounded-md border border-sand-300 bg-sand-0 p-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <Field label={t.cancelReason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>
      {state.error && <Notice tone="danger">{state.error.message}</Notice>}
      <button disabled={pending} className="rounded-sm px-4 py-2 text-sm font-semibold text-danger ring-1 ring-danger">{t.cancelWo}</button>
    </form>
  );
}
