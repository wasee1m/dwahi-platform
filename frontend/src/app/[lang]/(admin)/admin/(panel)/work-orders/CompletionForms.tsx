"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { completionStep } from "../service-actions";

type T = AppDictionary["admin"]["service"];

function message(state: AdminState): string | null {
  return state.error ? Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message : null;
}

/** 5.11 إلى 5.13: اعتماد، أو بلا تكلفة بسبب، أو إعادة للفني بملاحظة */
export function VerifyActions({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(completionStep, {});
  const [mode, setMode] = useState<"verify" | "no_charge" | "return">("verify");
  if (state.ok) return <Notice tone="success">{state.message === "return" ? t.returned : t.verified}</Notice>;
  const msg = message(state);

  return (
    <form action={action} className="space-y-3 rounded-md border-2 border-sun bg-sand-0 p-4 text-sm">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value={mode} />
      <h2 className="text-base">{t.completion}</h2>
      <div className="flex flex-wrap gap-2">
        {(["verify", "no_charge", "return"] as const).map((m) => (
          <label key={m} className="flex items-center gap-1">
            <input type="radio" name="mode" checked={mode === m} onChange={() => setMode(m)} />
            {m === "verify" ? t.verify : m === "no_charge" ? t.noCharge : t.returnToTech}
          </label>
        ))}
      </div>
      {mode === "no_charge" && <Field label={t.noChargeReason}><input name="reason" required minLength={3} maxLength={500} className={inputClass} /></Field>}
      {mode === "return" && <Field label={t.returnNote}><textarea name="note" required minLength={3} maxLength={1000} rows={3} className={inputClass} /></Field>}
      {msg && <Notice tone="danger">{msg}</Notice>}
      <button disabled={pending} className="w-full rounded-sm bg-sun px-4 py-2.5 font-bold text-on-sun disabled:opacity-60">
        {mode === "verify" ? t.verify : mode === "no_charge" ? t.noCharge : t.returnToTech}
      </button>
    </form>
  );
}

/** 4a.2 و 4a.3: تقدير إضافي يُحرر في A-06، أو رفض بسبب */
export function AdditionalActions({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(completionStep, {});
  const [rejecting, setRejecting] = useState(false);
  if (state.ok) return <Notice tone="info">{t.additionalStatuses.rejected}</Notice>;
  const msg = message(state);

  return (
    <form action={action} className="mt-2 flex flex-wrap items-end gap-2">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="op" value={rejecting ? "reject_additional" : "estimate_additional"} />
      {rejecting && <Field label={t.rejectReason}><input name="reason" required minLength={3} maxLength={1000} className={inputClass} /></Field>}
      <button disabled={pending} className="rounded-sm bg-night px-3 py-1.5 font-semibold text-on-night disabled:opacity-60">{rejecting ? t.rejectAdditional : t.estimateAdditional}</button>
      <button type="button" onClick={() => setRejecting(!rejecting)} className="px-3 py-1.5 text-ink-muted">{rejecting ? t.estimateAdditional : t.rejectAdditional}</button>
      {msg && <Notice tone="danger">{msg}</Notice>}
    </form>
  );
}
