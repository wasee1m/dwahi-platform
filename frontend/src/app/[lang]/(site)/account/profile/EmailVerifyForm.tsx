"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Me } from "@/lib/auth/client";
import { verifyEmail, type PortalState } from "../portal-actions";

// توثيق البريد برمز (C-02): شرط استعادة كلمة المرور (FR-AUTH-04)
export function EmailVerifyForm({ lang, t, me, codeLabel }: { lang: string; t: AppDictionary["profile"]; me: Me; codeLabel: string }) {
  const [state, action, pending] = useActionState<PortalState, FormData>(verifyEmail, {});
  if (!me.email) return null;
  if (me.email_verified) return <Notice tone="success">{t.emailVerified}</Notice>;
  const confirming = state.step === "confirm";
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-6">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="step" value={confirming ? "confirm" : "request"} />
      <h2 className="text-lg">{t.emailVerify}</h2>
      <p className="text-sm text-ink-muted">{t.emailVerifyWhy}</p>
      {state.debugCode && <Notice>{codeLabel}: <span className="num">{state.debugCode}</span></Notice>}
      {confirming && (
        <Field label={t.code} error={state.fieldErrors?.code?.[0]}>
          <input name="code" inputMode="numeric" maxLength={6} dir="ltr" required className={`${inputClass} num max-w-40`} />
        </Field>
      )}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <SubmitButton pending={pending}>{confirming ? t.confirm : t.emailSend}</SubmitButton>
    </form>
  );
}
