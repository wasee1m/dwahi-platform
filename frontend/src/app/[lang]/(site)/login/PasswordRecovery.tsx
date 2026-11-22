"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { forgotPassword, resetPassword, type RecoveryState } from "./recovery-actions";

type T = AppDictionary["recovery"];

export function ForgotPasswordForm({ lang, t, emailLabel }: { lang: string; t: T; emailLabel: string }) {
  const [state, action, pending] = useActionState<RecoveryState, FormData>(forgotPassword, {});
  if (state.ok) return <Notice tone="success">{t.sent}</Notice>;
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-5">
      <input type="hidden" name="lang" value={lang} />
      <Field label={emailLabel} error={state.fieldErrors?.email?.[0]}><input name="email" type="email" dir="ltr" required className={inputClass} /></Field>
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <SubmitButton pending={pending} className="w-full">{t.send}</SubmitButton>
    </form>
  );
}

export function NewPasswordForm({ lang, t, profile, email, token }: { lang: string; t: T; profile: AppDictionary["profile"]; email: string; token: string }) {
  const [state, action, pending] = useActionState<RecoveryState, FormData>(resetPassword, {});
  const invalidLink = state.fieldErrors?.token || state.fieldErrors?.email;
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-5">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="email" value={email} />
      <input type="hidden" name="token" value={token} />
      {invalidLink && <Notice tone="danger">{t.invalidLink}</Notice>}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <Field label={profile.newPassword} error={state.fieldErrors?.password?.[0]}>
        <input name="password" type="password" autoComplete="new-password" minLength={10} required className={inputClass} />
      </Field>
      <Field label={profile.confirmPassword}>
        <input name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />
      </Field>
      <SubmitButton pending={pending} className="w-full">{profile.setPassword}</SubmitButton>
    </form>
  );
}
