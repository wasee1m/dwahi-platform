"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { completeOnboarding, type ActionState } from "../account/actions";

// W-16 إكمال الملف (FR-AUTH-01، US-06): الاسم، نوع الحساب، بيانات الشركة المختصرة، البريد، الموافقات
export function OnboardingForm({ lang, t, legal }: { lang: string; t: AppDictionary["onboarding"]; legal: { terms: string; privacy: string } }) {
  const [state, action, pending] = useActionState<ActionState, FormData>(completeOnboarding, {});
  const [type, setType] = useState<"individual" | "company">("individual");
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  return (
    <form action={action} className="space-y-5" noValidate>
      <input type="hidden" name="lang" value={lang} />
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <Field label={t.fullName} error={err("full_name")}>
        <input name="full_name" required autoComplete="name" className={inputClass} aria-invalid={!!err("full_name")} />
      </Field>

      <fieldset>
        <legend className="mb-2 text-sm font-semibold">{t.accountType}</legend>
        <div className="grid grid-cols-2 gap-2">
          {(["individual", "company"] as const).map((v) => (
            <label key={v} className={`cursor-pointer rounded-sm border px-4 py-3 text-center font-semibold ${type === v ? "border-sun bg-sand-100" : "border-sand-300"}`}>
              <input type="radio" name="account_type" value={v} checked={type === v} onChange={() => setType(v)} className="sr-only" />
              {t[v]}
            </label>
          ))}
        </div>
      </fieldset>

      {type === "company" && (
        <div className="space-y-4 rounded-md bg-sand-100 p-4">
          <Field label={t.companyName} error={err("company_name")}>
            <input name="company_name" required autoComplete="organization" className={inputClass} aria-invalid={!!err("company_name")} />
          </Field>
          <Field label={t.governorate} error={err("governorate")}>
            <select name="governorate" required defaultValue="" className={inputClass}>
              <option value="" disabled>—</option>
              {Object.entries(t.governorates).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </Field>
          <Field label={t.crNumber} error={err("cr_number")}><input name="cr_number" dir="ltr" className={inputClass} /></Field>
          <Field label={t.vatNumber} error={err("vat_number")}><input name="vat_number" dir="ltr" className={inputClass} /></Field>
        </div>
      )}

      <Field label={t.email} error={err("email")}>
        <input name="email" type="email" dir="ltr" autoComplete="email" className={inputClass} aria-invalid={!!err("email")} />
      </Field>

      <div className="space-y-2 text-sm">
        <label className="flex items-start gap-2">
          <input type="checkbox" name="accept_terms" required className="mt-0.5 size-4 accent-[var(--sun)]" />
          <span><a href={legal.terms} target="_blank" className="underline">{t.acceptTerms}</a></span>
        </label>
        {err("accept_terms") && <p className="text-danger">{err("accept_terms")}</p>}
        <label className="flex items-start gap-2">
          <input type="checkbox" name="accept_privacy" required className="mt-0.5 size-4 accent-[var(--sun)]" />
          <span><a href={legal.privacy} target="_blank" className="underline">{t.acceptPrivacy}</a></span>
        </label>
        {err("accept_privacy") && <p className="text-danger">{err("accept_privacy")}</p>}
        {/* الموافقة التسويقية غير مفعلة افتراضياً (W-16) */}
        <label className="flex items-start gap-2">
          <input type="checkbox" name="marketing" className="mt-0.5 size-4 accent-[var(--sun)]" /> {t.marketing}
        </label>
      </div>

      <SubmitButton pending={pending} className="w-full">{t.submit}</SubmitButton>
    </form>
  );
}
