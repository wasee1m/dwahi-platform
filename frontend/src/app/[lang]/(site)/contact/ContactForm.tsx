"use client";

import { useActionState } from "react";
import { sendLead, type LeadState } from "./actions";

type Labels = {
  name: string; phone: string; email: string; company: string; topic: string; message: string; branch: string;
  submit: string; sending: string; success: string; error: string; required: string;
  topics: Record<"equipment" | "parts" | "service" | "other", string>;
};

const input = "w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2.5 focus:border-night aria-invalid:border-danger";

export function ContactForm({ lang, labels, branches, defaults }: {
  lang: string; labels: Labels; branches: { code: string; name: string }[];
  defaults: { topic?: string; message?: string };
}) {
  const [state, action, pending] = useActionState<LeadState, FormData>(sendLead, { status: "idle" });
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  if (state.status === "success") {
    return <p role="status" className="rounded-md bg-sand-0 p-6 font-semibold text-success ring-1 ring-sand-300">{labels.success}</p>;
  }

  const field = (name: string, label: string, el: React.ReactNode) => (
    <label className="block">
      <span className="mb-1 block text-sm font-semibold">{label}</span>
      {el}
      {err(name) && <span id={`${name}-err`} className="mt-1 block text-sm text-danger">{err(name)}</span>}
    </label>
  );

  return (
    <form action={action} className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-6 sm:grid-cols-2" noValidate>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="source_page" value={`/${lang}/contact`} />
      {/* حقل مخفي للروبوتات (NFR-09): الإنسان لا يراه ولا يملؤه */}
      <div className="absolute -start-[9999px]" aria-hidden="true">
        <input type="text" name="website" tabIndex={-1} autoComplete="off" />
      </div>

      {field("name", labels.name, <input name="name" required autoComplete="name" className={input} aria-invalid={!!err("name")} />)}
      {field("phone", labels.phone, <input name="phone" type="tel" required dir="ltr" autoComplete="tel" inputMode="tel" placeholder="9XXX XXXX" className={`${input} text-start`} aria-invalid={!!err("phone")} />)}
      {field("email", labels.email, <input name="email" type="email" dir="ltr" autoComplete="email" className={`${input} text-start`} aria-invalid={!!err("email")} />)}
      {field("company", labels.company, <input name="company" autoComplete="organization" className={input} />)}
      {field("topic", labels.topic, (
        <select name="topic" defaultValue={defaults.topic ?? "other"} className={input}>
          {Object.entries(labels.topics).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      ))}
      {branches.length > 1 && field("branch_code", labels.branch, (
        <select name="branch_code" className={input}>
          {branches.map((b) => <option key={b.code} value={b.code}>{b.name}</option>)}
        </select>
      ))}
      <div className="sm:col-span-2">
        {field("message", labels.message, <textarea name="message" required rows={5} defaultValue={defaults.message} className={input} aria-invalid={!!err("message")} />)}
      </div>

      {state.status === "error" && !state.fieldErrors?.name && (
        <p role="alert" className="text-sm text-danger sm:col-span-2">{labels.error}</p>
      )}
      <div className="sm:col-span-2">
        <button disabled={pending} className="rounded-sm bg-sun px-8 py-3 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">
          {pending ? labels.sending : labels.submit}
        </button>
      </div>
    </form>
  );
}
