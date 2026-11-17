"use client";

import { useActionState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { forgotPassword, resetPassword, type AdminState } from "../actions";

export function ForgotForm({ lang, t, auth }: { lang: string; t: AppDictionary["admin"]; auth: AppDictionary["auth"] }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(forgotPassword, {});
  if (state.ok) return <Notice tone="success">{t.forgotSent}</Notice>;
  return (
    <form action={action} className="space-y-5">
      <input type="hidden" name="lang" value={lang} />
      <Field label={auth.email}><input name="email" type="email" dir="ltr" required autoFocus className={inputClass} /></Field>
      <SubmitButton pending={pending} className="w-full">{t.send}</SubmitButton>
    </form>
  );
}

// تفعيل حساب موظف جديد واستعادة كلمة المرور: نفس الصفحة برابط البريد (US-39، FR-AUTH-04)
export function ResetForm({ lang, t, labels, email, token }: {
  lang: string; t: AppDictionary["admin"]; labels: AppDictionary["profile"]; email: string; token: string;
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(resetPassword, {});
  const tokenError = state.fieldErrors?.token?.[0];
  return (
    <form action={action} className="space-y-5">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="email" value={email} />
      <input type="hidden" name="token" value={token} />
      {tokenError && <Notice tone="danger">{t.invalidLink}</Notice>}
      <p className="num text-sm text-ink-muted" dir="ltr">{email}</p>
      <Field label={labels.newPassword} error={state.fieldErrors?.password?.[0]}>
        <input name="password" type="password" autoComplete="new-password" minLength={10} required className={inputClass} />
      </Field>
      <Field label={labels.confirmPassword}>
        <input name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />
      </Field>
      <SubmitButton pending={pending} className="w-full">{labels.setPassword}</SubmitButton>
    </form>
  );
}
