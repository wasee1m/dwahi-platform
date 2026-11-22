"use client";

import { useActionState, useMemo, useState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { fill } from "@/i18n/fill";
import type { Address, CatalogModel, FleetItem } from "@/lib/account-types";
import { addReading, saveEquipment, type PortalState } from "../portal-actions";
import { uploadPrivate } from "../quotes/actions";

type T = AppDictionary["fleet"];

function PhotoInput({ lang, name, label, t }: { lang: string; name: string; label: string; t: T }) {
  const [id, setId] = useState("");
  const [status, setStatus] = useState("");
  async function upload(file?: File) {
    if (!file) return;
    setStatus(t.uploading);
    const body = new FormData();
    body.set("lang", lang);
    body.set("file", file);
    const r = await uploadPrivate(body);
    setId(r.ok ? r.id : "");
    setStatus(r.ok ? t.uploaded : r.message);
  }
  return (
    <Field label={label}>
      {/* الملف يُرفع فوراً، والنموذج يرسل معرّفه فقط */}
      <input type="file" accept="image/jpeg,image/png" capture="environment" onChange={(e) => void upload(e.target.files?.[0])} className="block text-sm" />
      <input type="hidden" name={name} value={id} />
      {status && <span role="status" className="mt-1 block text-xs text-ink-muted">{status}</span>}
    </Field>
  );
}

export function EquipmentForm({ lang, t, models, sites, organizationId, item }: {
  lang: string; t: T; models: CatalogModel[]; sites: Address[]; organizationId: string | null; item?: FleetItem;
}) {
  const [state, action, pending] = useActionState<PortalState, FormData>(saveEquipment, {});
  const brands = useMemo(() => [...new Set(models.map((m) => m.brand))], [models]);
  const start = models.find((m) => m.id === item?.model_id);
  const [brand, setBrand] = useState(start?.brand ?? (item ? "" : (brands[0] ?? "")));
  const [model, setModel] = useState(start?.id ?? "");
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  return (
    <form action={action} onSubmit={keepValues(action)} className="grid gap-4 sm:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      {item ? <input type="hidden" name="id" value={item.id} /> : <input type="hidden" name="organization_id" value={organizationId ?? ""} />}
      <input type="hidden" name="model_id" value={brand ? model : ""} />
      <Field label={t.brand}>
        <select value={brand} onChange={(e) => { setBrand(e.target.value); setModel(""); }} className={inputClass}>
          {brands.map((b) => <option key={b} value={b}>{b}</option>)}
          <option value="">{t.otherModel}</option>
        </select>
      </Field>
      {brand ? (
        <Field label={t.model} error={err("model_id") ?? err("model_text")}>
          <select required value={model} onChange={(e) => setModel(e.target.value)} className={inputClass}>
            <option value="" disabled>—</option>
            {models.filter((m) => m.brand === brand).map((m) => <option key={m.id} value={m.id}>{m.model_name}</option>)}
          </select>
        </Field>
      ) : (
        <div className="grid gap-4 sm:col-span-2 sm:grid-cols-2">
          <Field label={t.brandText} error={err("brand_text")}><input name="brand_text" maxLength={100} defaultValue={start ? "" : (item?.brand ?? "")} className={inputClass} /></Field>
          <Field label={t.modelText} error={err("model_text")}><input name="model_text" required maxLength={100} defaultValue={start ? "" : (item?.model ?? "")} className={inputClass} /></Field>
        </div>
      )}
      <Field label={t.nickname} hint={t.nicknameHint} error={err("nickname")}><input name="nickname" maxLength={100} defaultValue={item?.nickname ?? ""} className={inputClass} /></Field>
      <Field label={t.serial} error={err("serial_number")}><input name="serial_number" maxLength={100} dir="ltr" defaultValue={item?.serial_number ?? ""} className={inputClass} /></Field>
      <Field label={t.year} error={err("year")}><input name="year" type="number" min={1950} max={2100} dir="ltr" defaultValue={item?.year ?? ""} className={`${inputClass} num`} /></Field>
      {!item && <Field label={t.meter} error={err("hour_meter")}><input name="hour_meter" type="number" min={0} max={999999} dir="ltr" className={`${inputClass} num`} /></Field>}
      <Field label={t.site} error={err("site_address_id")}>
        <select name="site_address_id" defaultValue={item?.site?.id ?? ""} className={inputClass}>
          <option value="">—</option>
          {sites.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
        </select>
      </Field>
      <PhotoInput lang={lang} name="nameplate_photo_id" label={t.nameplate} t={t} />
      <div className="space-y-2 sm:col-span-2">
        {state.ok && <Notice tone="success">{t.saved}</Notice>}
        {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
        <SubmitButton pending={pending}>{item ? t.save : t.add}</SubmitButton>
      </div>
    </form>
  );
}

export function ReadingForm({ lang, t, id }: { lang: string; t: T; id: string }) {
  const [value, setValue] = useState("");
  const [askedFor, setAskedFor] = useState("");
  const [photoKey, setPhotoKey] = useState(0);
  // بعد النجاح يُفرَّغ النموذج في مكانه، فتبقى رسالة النجاح ظاهرة
  const [state, action, pending] = useActionState<PortalState, FormData>(async (prev, form) => {
    const result = await addReading(prev, form);
    if (result.ok) {
      setValue("");
      setAskedFor("");
      setPhotoKey((k) => k + 1);
    }
    return result;
  }, {});
  // التأكيد يخص القيمة التي حُذر منها: تغييرها يلغيه
  const confirming = state.step === "confirm" && value === askedFor;
  const submit = keepValues(action);

  return (
    <form action={action} onSubmit={(e) => { setAskedFor(value); submit(e); }} className="space-y-3">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={id} />
      {confirming && <input type="hidden" name="confirm_lower" value="1" />}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t.newReading} error={state.fieldErrors?.reading?.[0]}>
          <input name="reading" type="number" min={0} max={999999} required dir="ltr" value={value} onChange={(e) => setValue(e.target.value)} className={`${inputClass} num text-xl`} />
        </Field>
        <PhotoInput key={photoKey} lang={lang} name="photo_id" label={t.readingPhoto} t={t} />
      </div>
      {confirming && (
        <>
          <Notice tone="danger">{fill(t.lower, { previous: String(state.previous) })}</Notice>
          <Field label={t.lowerNote} error={state.fieldErrors?.note?.[0]}><input name="note" required maxLength={500} className={inputClass} /></Field>
        </>
      )}
      {state.ok && <Notice tone="success">{t.readingSaved}</Notice>}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
      <SubmitButton pending={pending}>{confirming ? t.confirmLower : t.saveReading}</SubmitButton>
    </form>
  );
}
