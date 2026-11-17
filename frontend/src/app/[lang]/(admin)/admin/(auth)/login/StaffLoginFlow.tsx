"use client";

import Link from "next/link";
import { useActionState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { staffAuthStep, type AdminState } from "../../actions";

// A-21: البريد وكلمة المرور، ثم TOTP (أو إعداده عند أول دخول، NFR-08). حالة واحدة للخطوتين.
export function StaffLoginFlow({ lang, t, auth, next, notice }: {
  lang: string; t: AppDictionary["admin"]; auth: AppDictionary["auth"]; next?: string; notice?: string;
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(staffAuthStep, { step: "password" });

  const error = (() => {
    const e = state.error;
    if (!e) return undefined;
    if (e.code === "INVALID_CREDENTIALS") return auth.invalidCredentials;
    if (e.code === "ACCOUNT_SUSPENDED") return auth.suspended;
    if (e.code === "TWO_FACTOR_INVALID") return t.invalidCode;
    if (e.code === "MFA_EXPIRED") return t.stepExpired;
    if (e.code === "RATE_LIMITED") return auth.throttledFor.replace("{m}", "1");
    return auth.genericError;
  })();

  if (state.step !== "totp") {
    return (
      <form action={action} className="space-y-5" noValidate>
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="step" value="password" />
        {notice && !error && <Notice tone="success">{notice}</Notice>}
        {error && <Notice tone="danger">{error}</Notice>}
        <Field label={auth.email}><input name="email" type="email" dir="ltr" autoComplete="username" required autoFocus className={inputClass} /></Field>
        <Field label={auth.password}><input name="password" type="password" autoComplete="current-password" required className={inputClass} /></Field>
        <SubmitButton pending={pending} className="w-full">{t.next}</SubmitButton>
        <Link href={`/${lang}/admin/forgot`} className="block text-center text-sm font-semibold text-sun-text hover:underline">{auth.forgot}</Link>
      </form>
    );
  }

  const setup = state.setup;
  return (
    <form action={action} className="space-y-5" noValidate>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="step" value="totp" />
      {next && <input type="hidden" name="next" value={next} />}
      <h2 className="text-lg">{setup ? t.setupTitle : t.totpTitle}</h2>
      <p className="text-sm text-ink-muted">{setup ? t.setupIntro : t.totpIntro}</p>
      {setup && (
        <div className="flex flex-col items-center gap-3 rounded-md bg-sand-100 p-4">
          {/* SVG من مكتبة qrcode على الخادم، لا من مدخلات المستخدم */}
          <div className="rounded-sm bg-white p-2" dangerouslySetInnerHTML={{ __html: setup.qrSvg }} role="img" aria-label="QR" />
          <p className="text-xs text-ink-muted">{t.manualKey}</p>
          <code className="num break-all text-center text-sm font-semibold" dir="ltr">{setup.secret.match(/.{1,4}/g)?.join(" ")}</code>
        </div>
      )}
      {error && <Notice tone="danger">{error}</Notice>}
      <Field label={auth.code}>
        <input name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required autoFocus dir="ltr"
          className={`${inputClass} num text-center text-2xl tracking-[0.5em]`} />
      </Field>
      <SubmitButton pending={pending} className="w-full">{t.verify}</SubmitButton>
    </form>
  );
}
