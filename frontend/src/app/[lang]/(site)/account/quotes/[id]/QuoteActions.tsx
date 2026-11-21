"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { quoteAction, uploadPrivate, type QuoteState } from "../actions";

// C-09: الرد على العرض (FR-RFQ-03): قبول برقم أمر الشراء أو ملفه، أو رفض، أو طلب تعديل، أو إرسال للاعتماد
type T = AppDictionary["quotes"]["detail"];
type Panel = "accept" | "reject" | "revision" | null;

export function QuoteActions({ lang, t, rfqId, quotationId, canDecide, approvalRequested }: {
  lang: string; t: T; rfqId: string; quotationId: string; canDecide: boolean; approvalRequested: boolean;
}) {
  const [state, action, pending] = useActionState<QuoteState, FormData>(quoteAction, {});
  const [panel, setPanel] = useState<Panel>(null);
  const [lpo, setLpo] = useState<{ id: string; name: string } | null>(null);
  const [uploading, setUploading] = useState(false);
  const hidden = (op: string) => (
    <>
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="rfq" value={rfqId} />
      <input type="hidden" name="quotation" value={quotationId} />
      <input type="hidden" name="op" value={op} />
    </>
  );
  const btn = (p: Exclude<Panel, null>, label: string, primary = false) => (
    <button type="button" onClick={() => setPanel(panel === p ? null : p)} aria-expanded={panel === p}
      className={`rounded-sm px-5 py-2.5 font-bold ${primary ? "bg-sun text-on-sun hover:bg-sun-deep" : "border border-ink"}`}>{label}</button>
  );

  async function pickLpo(file?: File) {
    if (!file) return;
    setUploading(true);
    const f = new FormData();
    f.set("lang", lang);
    f.set("file", file);
    const r = await uploadPrivate(f);
    setUploading(false);
    if (r.ok) setLpo({ id: r.id, name: r.name });
  }

  if (state.ok && state.message === "approval") return <Notice tone="success">{t.approvalSent}</Notice>;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        {canDecide ? btn("accept", t.accept, true) : (
          <form action={action}>{hidden("approval")}
            <SubmitButton pending={pending} className="py-2.5" >{t.requestApproval}</SubmitButton>
          </form>
        )}
        {btn("revision", t.revise)}
        {canDecide && btn("reject", t.reject)}
      </div>
      {!canDecide && <p className="text-sm text-ink-muted">{approvalRequested ? t.approvalSent : t.needsApproval}</p>}

      {panel === "accept" && (
        <form action={action} className="grid gap-3 rounded-md border border-sand-300 bg-sand-0 p-4 md:grid-cols-2">
          {hidden("accept")}
          <Field label={t.lpoNumber}><input name="lpo_number" dir="ltr" className={inputClass} /></Field>
          <div>
            <span className="mb-1 block text-sm font-semibold">{t.lpoFile}</span>
            <input type="hidden" name="lpo_attachment_id" value={lpo?.id ?? ""} />
            <label className="inline-block cursor-pointer rounded-sm border border-ink px-3 py-2 text-sm font-semibold">
              {uploading ? "…" : lpo ? `📎 ${lpo.name}` : "PDF / JPG"}
              <input type="file" accept="application/pdf,image/jpeg,image/png" className="sr-only" onChange={(e) => { void pickLpo(e.target.files?.[0]); e.target.value = ""; }} />
            </label>
          </div>
          <div className="md:col-span-2"><SubmitButton pending={pending || uploading}>{t.confirmAccept}</SubmitButton></div>
        </form>
      )}
      {panel === "revision" && (
        <form action={action} className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4">
          {hidden("revision")}
          <Field label={t.reviseNote} error={state.fieldErrors?.note?.[0]}><textarea name="note" required rows={3} className={inputClass} /></Field>
          <SubmitButton pending={pending}>{t.sendRevision}</SubmitButton>
        </form>
      )}
      {panel === "reject" && (
        <form action={action} className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4">
          {hidden("reject")}
          <Field label={t.rejectReason}><input name="reason" className={inputClass} /></Field>
          <button disabled={pending} className="rounded-sm bg-danger px-5 py-2.5 font-bold text-white disabled:opacity-60">{t.confirmReject}</button>
        </form>
      )}
      {state.error && <Notice tone="danger">{state.fieldErrors ? Object.values(state.fieldErrors).flat()[0] : state.error.message}</Notice>}
    </div>
  );
}

/** "اطلب تجديد العرض" أو "إلغاء الطلب" (SM-1 1.9 و 1.3) */
export function RfqAction({ lang, rfqId, op, label, confirmLabel, reasonLabel, done }: {
  lang: string; rfqId: string; op: "renew" | "cancel"; label: string; confirmLabel?: string; reasonLabel?: string; done?: string;
}) {
  const [state, action, pending] = useActionState<QuoteState, FormData>(quoteAction, {});
  const [open, setOpen] = useState(op === "renew");
  if (state.ok && done) return <Notice tone="success">{done}</Notice>;
  return (
    <div className="space-y-2">
      {!open ? (
        <button type="button" onClick={() => setOpen(true)} className="rounded-sm border border-ink px-4 py-2 font-semibold">{label}</button>
      ) : (
        <form action={action} className="flex flex-wrap items-end gap-2">
          <input type="hidden" name="lang" value={lang} />
          <input type="hidden" name="rfq" value={rfqId} />
          <input type="hidden" name="op" value={op} />
          {reasonLabel && <Field label={reasonLabel}><input name="reason" className={inputClass} /></Field>}
          <button disabled={pending} className={`rounded-sm px-4 py-2.5 font-bold disabled:opacity-60 ${op === "cancel" ? "bg-danger text-white" : "bg-sun text-on-sun"}`}>{confirmLabel ?? label}</button>
        </form>
      )}
      {state.error && <Notice tone="danger">{state.error.message}</Notice>}
    </div>
  );
}
