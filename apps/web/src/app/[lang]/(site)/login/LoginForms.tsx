"use client";

import Link from "next/link";
import { useActionState, useEffect, useState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { emailLogin, requestCode, resendCode, verifyCode, type FormState } from "./actions";
import { authErrorMessage } from "./errors";

type T = AppDictionary["auth"];

export function PhoneForm({ lang, t, next }: { lang: string; t: T; next?: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(requestCode, {});
  return (
    <form action={action} className="space-y-5" noValidate>
      <input type="hidden" name="lang" value={lang} />
      {next && <input type="hidden" name="next" value={next} />}
      <Field label={t.phone} hint={t.phoneHint} error={authErrorMessage(state.error, t)}>
        <div className="flex" dir="ltr">
          <span className="flex items-center rounded-s-sm border border-e-0 border-sand-300 bg-sand-200 px-3 num">+968</span>
          <input name="phone" type="tel" inputMode="tel" autoComplete="tel" required autoFocus placeholder="9XXX XXXX"
            className={`${inputClass} rounded-s-none`} aria-invalid={!!state.error} />
        </div>
      </Field>
      <SubmitButton pending={pending} className="w-full">{t.sendCode}</SubmitButton>
    </form>
  );
}

export function CodeForm({ lang, t, sentAt, debugCode }: { lang: string; t: T; sentAt: number; debugCode?: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(verifyCode, {});
  const [resendState, resend, resending] = useActionState<FormState, FormData>(resendCode, {});
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);
  const lastSent = resendState.ok ? now : sentAt;
  const wait = Math.max(0, 60 - Math.floor((now - lastSent) / 1000));
  const error = authErrorMessage(state.error ?? resendState.error, t);

  return (
    <div className="space-y-5">
      {debugCode && <Notice>{t.devCode}: <span className="num">{debugCode}</span></Notice>}
      <form action={action} className="space-y-5" noValidate>
        <input type="hidden" name="lang" value={lang} />
        <Field label={t.code} error={error}>
          <input name="code" inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} required autoFocus dir="ltr"
            className={`${inputClass} num text-center text-2xl tracking-[0.5em]`} aria-invalid={!!error} />
        </Field>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" name="remember" className="size-4 accent-[var(--sun)]" /> {t.remember}
        </label>
        <SubmitButton pending={pending} className="w-full">{t.verify}</SubmitButton>
      </form>
      <form action={resend} className="flex items-center justify-between text-sm">
        <input type="hidden" name="lang" value={lang} />
        {wait > 0
          ? <span className="text-ink-muted">{t.resendIn.replace("{s}", String(wait))}</span>
          : <button disabled={resending} className="font-semibold text-sun-text hover:underline">{t.resend}</button>}
        <Link href={`/${lang}/login`} className="font-semibold text-ink-muted hover:underline">{t.changeNumber}</Link>
      </form>
    </div>
  );
}

export function EmailForm({ lang, t, next }: { lang: string; t: T; next?: string }) {
  const [state, action, pending] = useActionState<FormState, FormData>(emailLogin, {});
  const error = authErrorMessage(state.error, t);
  return (
    <form action={action} className="space-y-5" noValidate>
      <input type="hidden" name="lang" value={lang} />
      {next && <input type="hidden" name="next" value={next} />}
      {error && <Notice tone="danger">{error}</Notice>}
      <Field label={t.email}><input name="email" type="email" dir="ltr" autoComplete="email" required className={inputClass} /></Field>
      <Field label={t.password}><input name="password" type="password" autoComplete="current-password" required className={inputClass} /></Field>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" name="remember" className="size-4 accent-[var(--sun)]" /> {t.remember}
      </label>
      <SubmitButton pending={pending} className="w-full">{t.signIn}</SubmitButton>
    </form>
  );
}
