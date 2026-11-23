"use client";

import { useActionState, useState } from "react";
import { Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { reviewService, type ServiceState } from "../../service-actions";

type T = AppDictionary["service"];

function Stars({ name, label, required }: { name: string; label: string; required?: boolean }) {
  const [value, setValue] = useState(0);
  return (
    <fieldset className="text-sm">
      <legend className="font-semibold">{label}</legend>
      <input type="hidden" name={name} value={value || ""} />
      <div className="mt-1 flex gap-1" role="radiogroup" aria-label={label} aria-required={required}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={`${n}`} onClick={() => setValue(n)}
            className={`text-2xl leading-none ${n <= value ? "text-sun" : "text-sand-300"}`}>★</button>
        ))}
      </div>
    </fieldset>
  );
}

/** US-20: نجوم إجمالية ومعايير اختيارية وتعليق وموافقة على النشر (BR-10) */
export function ReviewForm({ lang, t, id, until }: { lang: string; t: T; id: string; until: string | null }) {
  const [state, action, pending] = useActionState<ServiceState, FormData>(reviewService, {});
  if (state.ok) return <Notice tone="success">{t.thanks}</Notice>;
  const message = state.error ? Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message : null;
  return (
    <form action={action} className="space-y-3 rounded-md border-2 border-sun bg-sand-0 p-5">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <h2 className="text-lg">{t.rateTitle}{until && <span className="text-sm font-normal text-ink-muted"> · {t.rateUntil} <span className="num">{until.slice(0, 10)}</span></span>}</h2>
      <Stars name="rating" label={t.rating} required />
      <div className="grid gap-3 sm:grid-cols-3">
        <Stars name="timeliness_rating" label={t.timeliness} />
        <Stars name="quality_rating" label={t.quality} />
        <Stars name="communication_rating" label={t.communication} />
      </div>
      <label className="block text-sm"><span className="font-semibold">{t.comment}</span>
        <textarea name="comment" maxLength={2000} rows={3} className="mt-1 w-full rounded-sm border border-sand-300 bg-sand-0 px-3 py-2" /></label>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="publish_consent" className="size-4" /> {t.publish}</label>
      {message && <Notice tone="danger">{message}</Notice>}
      <button disabled={pending} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun disabled:opacity-60">{t.sendRating}</button>
    </form>
  );
}
