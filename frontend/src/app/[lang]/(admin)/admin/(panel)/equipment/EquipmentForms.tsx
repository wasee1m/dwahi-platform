"use client";

import Image from "next/image";
import { useActionState, useState, useTransition } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { changeEquipmentStatus, saveEquipment, saveMedia, uploadPhoto, type Media } from "../catalog-actions";

// A-02: نموذج المعروض بأنواعه الثلاثة (BR-03)، والصور بترتيبها، وحالات النشر (FR-CAT-06، US-36)
export type Listing = {
  id: string; slug: string; listing_kind: Kind; model_id: string | null; component_type: string | null; component_model: string | null;
  source_machine: string | null; missing_parts: string | null; condition: string; warranty_months: number | null; year: number | null;
  hour_meter: number | null; serial_number: string | null; title_ar: string; title_en: string; description_ar: string | null;
  description_en: string | null; price_visibility: "shown" | "on_request"; price: string | null; status: Status; is_featured: boolean;
  branch_code: string | null; media: Media[]; allowed_transitions: Status[];
};
type Kind = "machine" | "component" | "scrap";
type Status = "draft" | "published" | "reserved" | "sold" | "archived";
type T = AppDictionary["admin"]["equipment"];
type C = AppDictionary["admin"]["common"];
type Option = { value: string; label: string };

const KINDS: Kind[] = ["machine", "component", "scrap"];
const COMPONENT_TYPES = ["diesel_engine", "transmission", "hydraulic_pump", "hydraulic_cylinder", "arm", "bucket", "other"] as const;

export function EquipmentForm({ lang, t, c, listing, models, branches }: {
  lang: string; t: T; c: C; listing?: Listing; models: Option[]; branches: Option[];
}) {
  const [state, action, pending] = useActionState<AdminState, FormData>(saveEquipment, {});
  const [kind, setKind] = useState<Kind>(listing?.listing_kind ?? "machine");
  const [condition, setCondition] = useState(listing?.condition && listing.condition !== "for_parts" ? listing.condition : "used");
  const [visibility, setVisibility] = useState(listing?.price_visibility ?? "on_request");
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  const usedMachine = kind === "machine" && condition !== "new";

  return (
    <form onSubmit={keepValues(action)} className="space-y-6 rounded-md border border-sand-300 bg-sand-0 p-6">
      <input type="hidden" name="lang" value={lang} />
      {listing && <input type="hidden" name="id" value={listing.id} />}

      <fieldset>
        <legend className="mb-2 text-sm font-semibold">{t.kind}</legend>
        <div className="flex flex-wrap gap-2">
          {KINDS.map((k) => (
            <label key={k} className={`cursor-pointer rounded-sm px-4 py-2 font-semibold ring-1 ${kind === k ? "bg-night text-on-night ring-night" : "bg-sand-100 ring-sand-300"}`}>
              <input type="radio" name="listing_kind" value={k} checked={kind === k} onChange={() => setKind(k)} className="sr-only" />
              {t.kinds[k]}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="grid gap-4 md:grid-cols-2">
        {kind === "component" ? (
          <>
            <Field label={t.componentType} error={err("component_type")}>
              <select name="component_type" defaultValue={listing?.component_type ?? "diesel_engine"} className={inputClass}>
                {COMPONENT_TYPES.map((x) => <option key={x} value={x}>{t.componentTypes[x]}</option>)}
              </select>
            </Field>
            <Field label={t.componentModel} error={err("component_model")}>
              <input name="component_model" dir="ltr" required defaultValue={listing?.component_model ?? ""} placeholder="CAT C9" className={inputClass} />
            </Field>
            <Field label={t.sourceMachine} error={err("source_machine")}>
              <input name="source_machine" defaultValue={listing?.source_machine ?? ""} className={inputClass} />
            </Field>
          </>
        ) : (
          <Field label={t.model} error={err("model_id")}>
            <select name="model_id" required defaultValue={listing?.model_id ?? ""} className={inputClass}>
              <option value="" disabled>{t.pickModel}</option>
              {models.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </Field>
        )}

        {kind === "scrap" ? (
          <input type="hidden" name="condition" value="for_parts" />
        ) : (
          <Field label={t.condition} error={err("condition")}>
            <select name="condition" value={condition} onChange={(e) => setCondition(e.target.value)} className={inputClass}>
              {(["new", "used", "rebuilt"] as const).map((x) => <option key={x} value={x}>{t.conditions[x]}</option>)}
            </select>
          </Field>
        )}

        <Field label={t.year} error={err("year")}>
          <input name="year" type="number" dir="ltr" min={1950} required={usedMachine || kind === "scrap"} defaultValue={listing?.year ?? ""} className={inputClass} />
        </Field>
        {kind !== "component" && (
          <Field label={t.hours} error={err("hour_meter")}>
            <input name="hour_meter" type="number" dir="ltr" min={0} required={usedMachine} defaultValue={listing?.hour_meter ?? ""} className={inputClass} />
          </Field>
        )}
        <Field label={t.serial} error={err("serial_number")}>
          <input name="serial_number" dir="ltr" defaultValue={listing?.serial_number ?? ""} className={inputClass} />
        </Field>
        <Field label={t.warranty} error={err("warranty_months")}>
          <input name="warranty_months" type="number" dir="ltr" min={0} max={120} defaultValue={listing?.warranty_months ?? ""} className={inputClass} />
        </Field>
        {kind === "scrap" && (
          <div className="md:col-span-2">
            <Field label={t.missingParts} error={err("missing_parts")}>
              <textarea name="missing_parts" required rows={3} defaultValue={listing?.missing_parts ?? ""} className={inputClass} />
            </Field>
          </div>
        )}
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t.titleAr} error={err("title_ar")}><input name="title_ar" required defaultValue={listing?.title_ar ?? ""} className={inputClass} /></Field>
        <Field label={t.titleEn} error={err("title_en")}><input name="title_en" dir="ltr" required defaultValue={listing?.title_en ?? ""} className={inputClass} /></Field>
        <Field label={t.descAr} error={err("description_ar")}><textarea name="description_ar" rows={5} defaultValue={listing?.description_ar ?? ""} className={inputClass} /></Field>
        <Field label={t.descEn} error={err("description_en")}><textarea name="description_en" dir="ltr" rows={5} defaultValue={listing?.description_en ?? ""} className={inputClass} /></Field>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Field label={t.priceVisibility}>
          <select name="price_visibility" value={visibility} onChange={(e) => setVisibility(e.target.value as Listing["price_visibility"])} className={inputClass}>
            <option value="on_request">{t.onRequest}</option>
            <option value="shown">{t.shown}</option>
          </select>
        </Field>
        <Field label={t.price} error={err("price")}>
          <input name="price" inputMode="decimal" dir="ltr" required={visibility === "shown"} defaultValue={listing?.price ?? ""} placeholder="0.000" className={inputClass} />
        </Field>
        <Field label={t.branch} error={err("branch_code")}>
          <select name="branch_code" defaultValue={listing?.branch_code ?? branches[0]?.value} className={inputClass}>
            {branches.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
          </select>
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_featured" defaultChecked={listing?.is_featured} className="size-4 accent-[var(--sun)]" /> {t.featured}
      </label>

      {state.error && <Notice tone="danger">{state.fieldErrors ? c.errorSave : state.error.message}</Notice>}
      {state.ok && <Notice tone="success">{c.saved}</Notice>}
      <SubmitButton pending={pending}>{c.save}</SubmitButton>
    </form>
  );
}

/** الصور: رفع واحدة تلو الأخرى، ثم ترتيب وحذف، ثم حفظ الترتيب. الأولى غلاف. */
export function PhotoManager({ lang, t, c, listingId, initial }: { lang: string; t: T; c: C; listingId: string; initial: Media[] }) {
  const [media, setMedia] = useState(initial);
  const [errors, setErrors] = useState<string[]>([]);
  const [uploading, setUploading] = useState(false);
  const [state, setState] = useState<AdminState>({});
  const [saving, startSave] = useTransition();

  async function onFiles(files: FileList | null) {
    if (!files?.length) return;
    setUploading(true);
    setErrors([]);
    const added: Media[] = [];
    for (const file of Array.from(files)) {
      const f = new FormData();
      f.set("lang", lang);
      f.set("file", file);
      const r = await uploadPhoto(f);
      if (r.ok) added.push(r.media);
      else setErrors((e) => [...e, `${file.name}: ${r.message}`]);
    }
    setMedia((m) => [...m, ...added]);
    setUploading(false);
    setState({});
  }
  const move = (i: number, d: -1 | 1) => setMedia((m) => {
    const next = [...m];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    return next;
  });

  return (
    <section className="space-y-4 rounded-md border border-sand-300 bg-sand-0 p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-xl">{t.photos}</h2>
        <label className="cursor-pointer rounded-sm border border-ink px-4 py-2 font-semibold">
          {uploading ? c.uploading : t.addPhotos}
          <input type="file" accept="image/jpeg,image/png" multiple disabled={uploading} className="sr-only" onChange={(e) => { void onFiles(e.target.files); e.target.value = ""; }} />
        </label>
      </div>
      <p className="text-sm text-ink-muted">{t.photosHint}</p>
      {errors.map((e) => <Notice key={e} tone="danger">{e}</Notice>)}
      <ol className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        {media.map((m, i) => (
          <li key={m.id} className={`overflow-hidden rounded-sm bg-sand-100 ${i === 0 ? "ring-2 ring-sun" : "ring-1 ring-sand-300"}`}>
            <Image src={m.url} alt="" width={400} height={300} unoptimized className="aspect-[4/3] w-full object-cover" />
            <div className="flex items-center justify-between gap-1 p-2 text-sm">
              <span className="num font-bold">{i + 1}</span>
              <span className="flex gap-1">
                <button type="button" disabled={i === 0} onClick={() => move(i, -1)} aria-label={c.up} className="rounded-sm px-2 py-1 hover:bg-sand-200 disabled:opacity-30">↑</button>
                <button type="button" disabled={i === media.length - 1} onClick={() => move(i, 1)} aria-label={c.down} className="rounded-sm px-2 py-1 hover:bg-sand-200 disabled:opacity-30">↓</button>
                <button type="button" onClick={() => setMedia((x) => x.filter((y) => y.id !== m.id))} className="rounded-sm px-2 py-1 text-danger hover:bg-sand-200">{c.remove}</button>
              </span>
            </div>
          </li>
        ))}
      </ol>
      <div className="flex items-center gap-3">
        <button type="button" disabled={saving || uploading} onClick={() => startSave(async () => setState(await saveMedia(lang, listingId, media.map((m) => m.id))))}
          className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{t.savePhotos}</button>
        {state.ok && <span className="text-sm font-semibold text-success">{c.saved}</span>}
        {state.error && <span className="text-sm text-danger">{state.error.message}</span>}
      </div>
    </section>
  );
}

/** أزرار الانتقالات المسموحة فقط (allowed_transitions من الـ API). السبب إلزامي للحجز ولإلغائه (BR-19). */
export function StatusPanel({ lang, t, listing }: { lang: string; t: T; listing: Listing }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(changeEquipmentStatus, {});
  const err = state.fieldErrors ? Object.values(state.fieldErrors).flat() : [];

  return (
    <section className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-6">
      <h2 className="text-xl">{t.status}: <span className="text-sun-deep">{t.statuses[listing.status]}</span></h2>
      <form action={action} className="space-y-3">
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="id" value={listing.id} />
        <input name="reason" placeholder={t.reasonRequired} className={inputClass} />
        <div className="flex flex-wrap gap-2">
          {listing.allowed_transitions.map((to) => (
            <button key={to} name="to" value={to} disabled={pending}
              className={`rounded-sm px-4 py-2 font-semibold disabled:opacity-60 ${to === "published" ? "bg-sun text-on-sun" : "border border-ink"}`}>
              {t.actions[to]}
            </button>
          ))}
        </div>
      </form>
      {err.map((e) => <Notice key={e} tone="danger">{e}</Notice>)}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
    </section>
  );
}
