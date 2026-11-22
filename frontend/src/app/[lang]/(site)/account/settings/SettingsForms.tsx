"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Preferences } from "@/lib/account-types";
import { deleteAccount, savePreferences, type PortalState } from "../portal-actions";

type T = AppDictionary["privacySettings"];
type Cell = Preferences["preferences"][number];

const CATEGORIES: Cell["category"][] = ["quotes", "orders", "service", "messages", "marketing"];
const CHANNELS: Cell["channel"][] = ["push", "email", "sms"];

export function PreferencesForm({ lang, t, data }: { lang: string; t: T; data: Preferences }) {
  const [state, action, pending] = useActionState<PortalState, FormData>(savePreferences, {});
  const cell = (category: Cell["category"], channel: Cell["channel"]) => data.preferences.find((p) => p.category === category && p.channel === channel);
  // التسويقي بلا موافقة لا أثر لمفاتيحه
  const categories = CATEGORIES.filter((c) => c !== "marketing" || data.marketing_consent);

  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3">
      <input type="hidden" name="lang" value={lang} />
      <div className="overflow-x-auto">
        <table className="w-full min-w-[460px] text-sm">
          <thead className="bg-sand-200">
            <tr>
              <th scope="col" className="px-3 py-2 text-start">{t.category}</th>
              {CHANNELS.map((ch) => <th key={ch} scope="col" className="px-3 py-2">{t.channels[ch]}</th>)}
            </tr>
          </thead>
          <tbody className="divide-y divide-sand-300">
            {categories.map((category) => (
              <tr key={category}>
                <th scope="row" className="px-3 py-3 text-start">{t.categories[category]}</th>
                {CHANNELS.map((channel) => {
                  const p = cell(category, channel);
                  const key = `${category}:${channel}`;
                  const label = `${t.categories[category]} · ${t.channels[channel]}`;
                  if (!p || p.state === "unused") return <td key={key} className="text-center text-ink-muted">—</td>;
                  const locked = p.state === "locked";
                  return (
                    <td key={key} className="px-3 py-3 text-center">
                      {!locked && <input type="hidden" name="editable" value={key} />}
                      <input type="checkbox" name={locked ? undefined : key} aria-label={label} defaultChecked={p.enabled} disabled={locked} className="size-5" />
                      {locked && <span className="block text-xs text-ink-muted">{t.mandatory}</span>}
                      {!locked && p.has_mandatory && <span className="block text-xs text-ink-muted">{t.partlyMandatory}</span>}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-ink-muted">{t.mandatoryHint}</p>
      {state.ok && <Notice tone="success">{t.saved}</Notice>}
      {state.error && <Notice tone="danger">{state.error.message}</Notice>}
      <SubmitButton pending={pending}>{t.save}</SubmitButton>
    </form>
  );
}

export function DeleteAccountForm({ lang, t, codeLabel }: { lang: string; t: T; codeLabel: string }) {
  const [state, action, pending] = useActionState<PortalState, FormData>(deleteAccount, {});
  const confirming = state.step === "confirm";
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="step" value={confirming ? "confirm" : "request"} />
      {state.debugCode && <Notice>{codeLabel}: <span className="num">{state.debugCode}</span></Notice>}
      {confirming && (
        <Field label={t.deleteCode} error={state.fieldErrors?.code?.[0]}>
          <input name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} dir="ltr" required className={`${inputClass} num max-w-40`} />
        </Field>
      )}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <button disabled={pending} className="rounded-sm px-5 py-2.5 font-bold text-danger ring-1 ring-danger disabled:opacity-60">
        {confirming ? t.deleteConfirm : t.deleteStart}
      </button>
    </form>
  );
}
