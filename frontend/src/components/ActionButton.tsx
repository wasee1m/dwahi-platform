"use client";

import { useActionState, useState } from "react";

// زر إجراء واحد بخطأه تحته. مع confirm: ضغطة ثانية في مكانه للتأكيد، بلا نافذة منبثقة تحجب الصفحة.
type Result = { ok?: boolean; error?: { code: string; message?: string }; fieldErrors?: Record<string, string[]> };

const LOOK = {
  plain: "border border-ink hover:bg-sand-200",
  danger: "text-danger ring-1 ring-danger hover:bg-sand-200",
  primary: "bg-sun text-on-sun hover:bg-sun-deep",
};

export function ActionButton<S extends Result>({ action, fields, label, confirm, look = "plain" }: {
  action: (prev: S, form: FormData) => Promise<S>;
  fields: Record<string, string>;
  label: string;
  confirm?: { question: string; yes: string; no: string };
  look?: keyof typeof LOOK;
}) {
  const [state, dispatch, pending] = useActionState<Result, FormData>((prev, form) => action(prev as S, form), {});
  const [asking, setAsking] = useState(false);
  const button = `rounded-sm px-4 py-2 text-sm font-semibold disabled:opacity-60 ${LOOK[look]}`;
  const message = state.error && (Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message);

  return (
    <form action={dispatch} className="inline-flex flex-col items-start gap-1">
      {Object.entries(fields).map(([name, value]) => <input key={name} type="hidden" name={name} value={value} />)}
      {confirm && !asking ? (
        <button key="ask" type="button" onClick={() => setAsking(true)} className={button}>{label}</button>
      ) : (
        <span key="do" className="inline-flex flex-wrap items-center gap-2">
          {confirm && <span className="text-sm">{confirm.question}</span>}
          <button disabled={pending} className={button}>{confirm ? confirm.yes : label}</button>
          {confirm && <button type="button" onClick={() => setAsking(false)} className="px-2 py-2 text-sm text-ink-muted">{confirm.no}</button>}
        </span>
      )}
      {message && <span role="alert" className="text-sm text-danger">{message}</span>}
    </form>
  );
}
