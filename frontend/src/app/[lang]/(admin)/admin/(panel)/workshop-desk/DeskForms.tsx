"use client";

import { useActionState, useEffect, useRef, useState } from "react";
import { Field, inputClass, keepValues, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { findCustomer, staffUpload, type Customer } from "../rfq-actions";
import { handOverItem, receiveItem } from "../service-actions";

type T = AppDictionary["admin"]["service"];

/** لوحة توقيع باللمس أو الفأرة، تُرفع صورة PNG خاصة */
export function SignaturePad({ lang, t, onUploaded }: { lang: string; t: T; onUploaded: (id: string | null) => void }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const [dirty, setDirty] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    const ctx = canvas.current?.getContext("2d");
    if (!ctx) return;
    ctx.lineWidth = 2.5;
    ctx.lineCap = "round";
    ctx.strokeStyle = "#231f1a";
  }, []);

  const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    return [(e.clientX - r.left) * (e.currentTarget.width / r.width), (e.clientY - r.top) * (e.currentTarget.height / r.height)] as const;
  };

  async function save() {
    const blob = await new Promise<Blob | null>((res) => canvas.current?.toBlob(res, "image/png"));
    if (!blob) return;
    const form = new FormData();
    form.set("file", new File([blob], "signature.png", { type: "image/png" }));
    form.set("lang", lang);
    const r = await staffUpload(form);
    setSaved(r.ok);
    onUploaded(r.ok ? r.id : null);
  }

  function clear() {
    const c = canvas.current;
    c?.getContext("2d")?.clearRect(0, 0, c.width, c.height);
    setDirty(false);
    setSaved(false);
    onUploaded(null);
  }

  return (
    <div className="space-y-2">
      <p className="font-semibold">{t.signature}</p>
      <canvas ref={canvas} width={600} height={180} aria-label={t.signature}
        className="w-full touch-none rounded-sm border border-sand-300 bg-white"
        onPointerDown={(e) => { drawing.current = true; const [x, y] = point(e); const ctx = e.currentTarget.getContext("2d")!; ctx.beginPath(); ctx.moveTo(x, y); }}
        onPointerMove={(e) => { if (!drawing.current) return; const [x, y] = point(e); const ctx = e.currentTarget.getContext("2d")!; ctx.lineTo(x, y); ctx.stroke(); setDirty(true); setSaved(false); }}
        onPointerUp={() => { drawing.current = false; }} onPointerLeave={() => { drawing.current = false; }} />
      <div className="flex gap-2 text-sm">
        <button type="button" disabled={!dirty || saved} onClick={save} className="rounded-sm bg-night px-3 py-1.5 font-semibold text-on-night disabled:opacity-50">{saved ? t.signed : t.saveSignature}</button>
        <button type="button" onClick={clear} className="px-3 py-1.5 text-ink-muted">{t.clearSignature}</button>
      </div>
    </div>
  );
}

/** حقول بطاقة الاستلام المشتركة بين الطلب القائم والحضور دون طلب */
function IntakeFields({ lang, t, equipment, onSignature, onPhotos }: {
  lang: string; t: T; equipment: boolean; onSignature: (id: string | null) => void; onPhotos: (ids: string[]) => void;
}) {
  const [photos, setPhotos] = useState<{ id: string; name: string }[]>([]);
  const [busy, setBusy] = useState(false);
  async function add(files: FileList | null) {
    setBusy(true);
    const next = [...photos];
    for (const f of Array.from(files ?? [])) {
      const form = new FormData();
      form.set("file", f);
      form.set("lang", lang);
      const r = await staffUpload(form);
      if (r.ok) next.push({ id: r.id, name: r.name });
    }
    setPhotos(next);
    onPhotos(next.map((p) => p.id));
    setBusy(false);
  }
  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t.broughtBy}><input name="brought_by_name" required maxLength={190} className={inputClass} /></Field>
        <Field label={t.broughtByPhone}><input name="brought_by_phone" type="tel" dir="ltr" maxLength={20} className={inputClass} /></Field>
      </div>
      {equipment && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t.hourMeter}><input name="hour_meter_reading" type="number" min={0} max={999999} className={inputClass} /></Field>
          <Field label={t.meterMissing} hint={t.meterMissingHint}><input name="hour_meter_missing_reason" maxLength={500} className={inputClass} /></Field>
        </div>
      )}
      <Field label={t.condition}><textarea name="condition_notes" rows={2} maxLength={2000} className={inputClass} /></Field>
      <Field label={t.accessories}><textarea name="accessories" rows={2} maxLength={2000} className={inputClass} /></Field>
      <div className="text-sm">
        <p className="font-semibold">{t.conditionPhotos}</p>
        <input type="file" accept="image/*" capture="environment" multiple disabled={busy} onChange={(e) => { void add(e.target.files); e.target.value = ""; }} className="mt-1 block" />
        {photos.length > 0 && <p className="mt-1 text-ink-muted">{photos.map((p) => p.name).join("، ")}</p>}
      </div>
      <SignaturePad lang={lang} t={t} onUploaded={onSignature} />
    </>
  );
}

export function IntakeForm({ lang, t, id, equipment }: { lang: string; t: T; id: string; equipment: boolean }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(receiveItem, {});
  const [signature, setSignature] = useState<string | null>(null);
  const [photos, setPhotos] = useState<string[]>([]);
  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-4 rounded-md border-2 border-sun bg-sand-0 p-5">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      {signature && <input type="hidden" name="signature_id" value={signature} />}
      {photos.map((p) => <input key={p} type="hidden" name="photo_id" value={p} />)}
      <h2 className="text-lg">{t.receiveTitle}</h2>
      <IntakeFields lang={lang} t={t} equipment={equipment} onSignature={setSignature} onPhotos={setPhotos} />
      {state.error && <Notice tone="danger">{Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message}</Notice>}
      <button disabled={pending || !signature} className="w-full rounded-sm bg-sun px-4 py-3 font-bold text-on-sun disabled:opacity-50">{t.saveIntake}</button>
    </form>
  );
}

/** US-48: عميل حاضر دون طلب. البحث بجواله، ثم الطلب والاستلام في خطوة واحدة. */
export function WalkIn({ lang, t, components, urgencies }: { lang: string; t: T; components: Record<string, string>; urgencies: Record<string, string> }) {
  const [phone, setPhone] = useState("");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [lookupDone, setLookupDone] = useState(false);
  const [kind, setKind] = useState<"machine" | "component">("component");
  const [state, action, pending] = useActionState<AdminState, FormData>(receiveItem, {});
  const [signature, setSignature] = useState<string | null>(null);
  const [photos, setPhotos] = useState<string[]>([]);

  if (!customer) {
    return (
      <div className="space-y-2 rounded-md border border-sand-300 bg-sand-0 p-4">
        <Field label={t.customerPhone}><input value={phone} onChange={(e) => setPhone(e.target.value)} type="tel" dir="ltr" className={inputClass} /></Field>
        <button type="button" onClick={async () => { const r = await findCustomer(lang, phone); setCustomer(r.customer); setLookupDone(true); }}
          className="rounded-sm bg-night px-4 py-2 text-sm font-bold text-on-night">{t.findCustomer}</button>
        {lookupDone && !customer && <p className="text-sm text-ink-muted">{t.customerNotFound}</p>}
      </div>
    );
  }

  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-4 rounded-md border border-sand-300 bg-sand-0 p-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="walk_in" value="1" />
      <input type="hidden" name="customer_id" value={customer.id} />
      {signature && <input type="hidden" name="signature_id" value={signature} />}
      {photos.map((p) => <input key={p} type="hidden" name="photo_id" value={p} />)}
      <p className="text-sm"><b>{customer.full_name}</b> · <span className="num" dir="ltr">{customer.phone}</span>
        <button type="button" onClick={() => setCustomer(null)} className="ms-2 text-ink-muted underline">{t.change}</button></p>
      <div className="flex gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="radio" checked={kind === "component"} onChange={() => setKind("component")} className="size-4" /> {t.component}</label>
        <label className="flex items-center gap-2"><input type="radio" checked={kind === "machine"} onChange={() => setKind("machine")} className="size-4" /> {t.machine}</label>
      </div>
      {kind === "component" ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t.componentType}>
            <select name="component_type" defaultValue="hydraulic_cylinder" className={inputClass}>
              {Object.entries(components).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <Field label={t.componentDetails}><input name="component_details" maxLength={1000} className={inputClass} /></Field>
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t.brand}><input name="brand_text" maxLength={100} className={inputClass} /></Field>
          <Field label={t.model}><input name="model_text" required maxLength={100} className={inputClass} /></Field>
          <Field label={t.serial}><input name="serial_number" dir="ltr" maxLength={100} className={inputClass} /></Field>
        </div>
      )}
      <Field label={t.problem}><textarea name="problem_description" required rows={2} maxLength={4000} className={inputClass} /></Field>
      <Field label={t.priority}>
        <select name="urgency" defaultValue="normal" className={inputClass}>
          <option value="normal">{urgencies.normal}</option>
          <option value="urgent">{urgencies.urgent}</option>
        </select>
      </Field>
      <IntakeFields lang={lang} t={t} equipment={kind === "machine"} onSignature={setSignature} onPhotos={setPhotos} />
      {state.error && <Notice tone="danger">{Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message}</Notice>}
      <button disabled={pending || !signature} className="w-full rounded-sm bg-sun px-4 py-3 font-bold text-on-sun disabled:opacity-50">{t.saveWalkIn}</button>
    </form>
  );
}

type Methods = Record<"cash" | "pos_card" | "bank_transfer" | "cheque", string>;

/** US-49: المستلم وتوقيعه، والدفعة بالمستحق كاملاً إن وُجد، في خطوة واحدة (BR-20)، ثم إيصال التسليم */
export function HandoverForm({ lang, t, id, due, methods }: { lang: string; t: T; id: string; due: string; methods: Methods }) {
  const [state, action, pending] = useActionState<AdminState & { receipt?: string | null }, FormData>(handOverItem, {});
  const [signature, setSignature] = useState<string | null>(null);
  const paying = Number(due) > 0;
  const message = state.error ? Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message : null;

  if (state.ok) {
    return (
      <Notice tone="success">{t.handedOver}{" "}
        {state.receipt && <a href={state.receipt} target="_blank" rel="noopener noreferrer" className="font-semibold underline">{t.handoverReceipt}</a>}
      </Notice>
    );
  }

  return (
    <form action={action} onSubmit={keepValues(action)} className="space-y-3 rounded-md border-2 border-sun bg-sand-0 p-4 text-sm">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      <input type="hidden" name="signature_id" value={signature ?? ""} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t.receiverName}><input name="receiver_name" required minLength={2} maxLength={120} className={inputClass} /></Field>
        <Field label={t.receiverPhone}><input name="receiver_phone" inputMode="tel" dir="ltr" maxLength={20} className={inputClass} /></Field>
      </div>
      {paying ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t.paymentMethod}>
            <select name="method" defaultValue="cash" className={inputClass}>
              {(Object.keys(methods) as (keyof Methods)[]).map((m) => <option key={m} value={m}>{methods[m]}</option>)}
            </select>
          </Field>
          <Field label={t.amount}><input name="amount" readOnly value={due} dir="ltr" className={`${inputClass} num`} /></Field>
        </div>
      ) : <p className="text-ink-muted">{t.noPaymentNeeded}</p>}
      <SignaturePad lang={lang} t={t} onUploaded={setSignature} />
      {message && <Notice tone="danger">{message}</Notice>}
      <button disabled={pending || !signature} className="w-full rounded-sm bg-sun px-4 py-2.5 font-bold text-on-sun disabled:opacity-60">
        {paying ? t.confirmHandover : t.confirmHandoverFree}
      </button>
    </form>
  );
}
