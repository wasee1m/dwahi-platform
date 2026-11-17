"use client";

import { useActionState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Me } from "@/lib/auth/client";
import { changePhone, setPassword, updateProfile, type ActionState } from "../actions";

type T = AppDictionary["profile"];
const card = "rounded-md border border-sand-300 bg-sand-0 p-6";

export function PersonalForm({ lang, t, me }: { lang: string; t: T; me: Me }) {
  const [state, action, pending] = useActionState<ActionState, FormData>(updateProfile, {});
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  return (
    <form action={action} className={`${card} grid gap-4 sm:grid-cols-2`}>
      <input type="hidden" name="lang" value={lang} />
      <h2 className="text-lg sm:col-span-2">{t.personal}</h2>
      <Field label={t.fullName} error={err("full_name")}><input name="full_name" defaultValue={me.full_name} required className={inputClass} /></Field>
      <Field label={t.email} error={err("email")}><input name="email" type="email" dir="ltr" defaultValue={me.email ?? ""} className={inputClass} /></Field>
      <Field label={t.language}>
        <select name="preferred_locale" defaultValue={me.preferred_locale} className={inputClass}>
          <option value="ar">العربية</option>
          <option value="en">English</option>
        </select>
      </Field>
      <div className="flex items-end gap-3 sm:col-span-2">
        <SubmitButton pending={pending}>{t.save}</SubmitButton>
        {state.ok && <span role="status" className="text-sm font-semibold text-success">{t.saved}</span>}
      </div>
    </form>
  );
}

export function PhoneForm({ lang, t, me, codeLabel }: { lang: string; t: T; me: Me; codeLabel: string }) {
  const [state, action, pending] = useActionState<ActionState, FormData>(changePhone, {});
  const verifying = state.step === "verify";
  const message = state.error?.code === "PHONE_TAKEN" ? t.phoneTaken : state.error?.message;
  return (
    <form action={action} className={`${card} space-y-4`}>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="step" value={verifying ? "verify" : "request"} />
      <h2 className="text-lg">{t.phoneTitle}</h2>
      <p className="text-sm text-ink-muted">{t.current}: <span className="num">{me.phone}</span></p>
      {state.ok && <Notice tone="success">{t.phoneChanged}</Notice>}
      {message && <Notice tone="danger">{message}</Notice>}
      {state.debugCode && <Notice>{codeLabel}: <span className="num">{state.debugCode}</span></Notice>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t.newPhone}>
          <input name="phone" type="tel" dir="ltr" inputMode="tel" required readOnly={verifying} placeholder="9XXX XXXX" className={inputClass} />
        </Field>
        {verifying && (
          <Field label={t.code}>
            <input name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} dir="ltr" required autoFocus className={`${inputClass} num`} />
          </Field>
        )}
      </div>
      <SubmitButton pending={pending}>{verifying ? t.confirm : t.sendCode}</SubmitButton>
    </form>
  );
}

export function PasswordForm({ lang, t, me }: { lang: string; t: T; me: Me }) {
  const [state, action, pending] = useActionState<ActionState, FormData>(setPassword, {});
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  return (
    <form action={action} className={`${card} space-y-4`}>
      <input type="hidden" name="lang" value={lang} />
      <h2 className="text-lg">{t.passwordTitle}</h2>
      <p className="text-sm text-ink-muted">{t.passwordIntro}</p>
      {!me.email ? <p className="text-sm font-semibold">{t.needEmail}</p> : (
        <>
          {state.ok && <Notice tone="success">{t.passwordSaved}</Notice>}
          {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
          <div className="grid gap-4 sm:grid-cols-2">
            {me.has_password && (
              <Field label={t.currentPassword} error={err("current_password")}>
                <input name="current_password" type="password" autoComplete="current-password" className={inputClass} />
              </Field>
            )}
            <Field label={t.newPassword} error={err("password")}>
              <input name="password" type="password" autoComplete="new-password" minLength={10} required className={inputClass} />
            </Field>
            <Field label={t.confirmPassword}>
              <input name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />
            </Field>
          </div>
          <SubmitButton pending={pending}>{t.setPassword}</SubmitButton>
        </>
      )}
    </form>
  );
}
