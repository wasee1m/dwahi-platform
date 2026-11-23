"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { adjustStock, importStep, receiveStock, savePart, type ImportState } from "../catalog-actions";
import { listSep } from "@/lib/format";

// A-04: القطعة وأرقامها البديلة وتوافقها، وتسوية المخزون، والاستلام، والاستيراد (FR-PRT-04، US-37)
type T = AppDictionary["admin"]["parts"];
type C = AppDictionary["admin"]["common"];
type Option = { value: string; label: string };
export type Part = {
  id: string; brand_id: string; part_number: string; name_ar: string; name_en: string; description_ar: string | null; description_en: string | null;
  category: string | null; unit: "piece" | "set" | "liter" | "meter" | "kg"; is_returnable: boolean; warranty_months: number | null; is_active: boolean;
  price: string; vat_rate: string; weight_kg: string | null; brand: string;
  alternatives: { number: string; brand: string | null }[]; compatible_models: { id: string; name: string }[];
  stock: { location_id: string; location: string; on_hand: string; reserved: string }[];
  movements: { qty_delta: string; reason: keyof T["reasons"]; note: string | null; location: string; by: string | null; at: string }[];
};
const UNITS = ["piece", "set", "liter", "meter", "kg"] as const;

export function PartForm({ lang, t, c, part, brands, models }: { lang: string; t: T; c: C; part?: Part; brands: Option[]; models: Option[] }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(savePart, {});
  const [filter, setFilter] = useState("");
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  const altErr = Object.entries(state.fieldErrors ?? {}).find(([k]) => k.startsWith("alternatives"))?.[1][0];
  const selected = new Set(part?.compatible_models.map((m) => m.id));
  const alternatives = (part?.alternatives ?? []).map((a) => (a.brand ? `${a.number} | ${a.brand}` : a.number)).join("\n");

  return (
    <form onSubmit={keepValues(action)} className="space-y-5 rounded-md border border-sand-300 bg-sand-0 p-6">
      <input type="hidden" name="lang" value={lang} />
      {part && <input type="hidden" name="id" value={part.id} />}
      <div className="grid gap-4 md:grid-cols-3">
        <Field label={t.brand} error={err("brand_id")}>
          <select name="brand_id" required defaultValue={part?.brand_id ?? ""} className={inputClass}>
            <option value="" disabled>—</option>
            {brands.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
          </select>
        </Field>
        <Field label={t.partNumber} error={err("part_number")}><input name="part_number" required dir="ltr" defaultValue={part?.part_number} className={inputClass} /></Field>
        <Field label={t.category} error={err("category")}><input name="category" dir="ltr" defaultValue={part?.category ?? ""} placeholder="filters" className={inputClass} /></Field>
        <Field label={t.nameAr} error={err("name_ar")}><input name="name_ar" required defaultValue={part?.name_ar} className={inputClass} /></Field>
        <Field label={t.nameEn} error={err("name_en")}><input name="name_en" required dir="ltr" defaultValue={part?.name_en} className={inputClass} /></Field>
        <Field label={t.unit} error={err("unit")}>
          <select name="unit" defaultValue={part?.unit ?? "piece"} className={inputClass}>{UNITS.map((u) => <option key={u} value={u}>{t.units[u]}</option>)}</select>
        </Field>
        <Field label={t.price} error={err("price")}><input name="price" required inputMode="decimal" dir="ltr" defaultValue={part?.price} placeholder="0.000" className={inputClass} /></Field>
        <Field label={t.vat} error={err("vat_rate")}><input name="vat_rate" required inputMode="decimal" dir="ltr" defaultValue={part?.vat_rate ?? "5.00"} className={inputClass} /></Field>
        <Field label={t.warranty} error={err("warranty_months")}><input name="warranty_months" type="number" min={0} max={120} dir="ltr" defaultValue={part?.warranty_months ?? ""} className={inputClass} /></Field>
        <Field label={t.weight} error={err("weight_kg")}><input name="weight_kg" inputMode="decimal" dir="ltr" defaultValue={part?.weight_kg ?? ""} className={inputClass} /></Field>
        <label className="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="is_returnable" defaultChecked={part?.is_returnable ?? true} className="size-4 accent-[var(--sun)]" /> {t.returnable}</label>
        <label className="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="is_active" defaultChecked={part?.is_active ?? true} className="size-4 accent-[var(--sun)]" /> {t.active}</label>
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t.descAr} error={err("description_ar")}><textarea name="description_ar" rows={3} defaultValue={part?.description_ar ?? ""} className={inputClass} /></Field>
        <Field label={t.descEn} error={err("description_en")}><textarea name="description_en" rows={3} dir="ltr" defaultValue={part?.description_en ?? ""} className={inputClass} /></Field>
        <Field label={t.alternatives} hint={t.altLine} error={altErr}><textarea name="alternatives" rows={4} dir="ltr" defaultValue={alternatives} placeholder={"SMP-6140 | Sakura\n1R0750"} className={inputClass} /></Field>
        <div>
          <span className="mb-1 block text-sm font-semibold">{t.compatible}</span>
          <input type="search" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={c.search} dir="ltr" className={`${inputClass} mb-2`} />
          <div className="h-40 space-y-1 overflow-y-auto rounded-sm border border-sand-300 bg-sand-100 p-2">
            {models.map((m) => (
              <label key={m.value} dir="ltr" className={`flex items-center gap-2 text-sm ${m.label.toLowerCase().includes(filter.toLowerCase()) ? "" : "hidden"}`}>
                <input type="checkbox" name="compatible_model_ids" value={m.value} defaultChecked={selected.has(m.value)} className="size-4 accent-[var(--sun)]" /> {m.label}
              </label>
            ))}
          </div>
        </div>
      </div>
      {state.error && <Notice tone="danger">{state.fieldErrors ? c.errorSave : state.error.message}</Notice>}
      {state.ok && <Notice tone="success">{c.saved}</Notice>}
      <SubmitButton pending={pending}>{c.save}</SubmitButton>
    </form>
  );
}

export function AdjustForm({ lang, t, c, partId, locations }: { lang: string; t: T; c: C; partId: string; locations: Option[] }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(adjustStock, {});
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  return (
    <form action={action} className="grid items-start gap-3 md:grid-cols-[1fr_10rem_2fr_auto]">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="id" value={partId} />
      <Field label={t.location} error={err("location_id")}>
        <select name="location_id" className={inputClass}>{locations.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}</select>
      </Field>
      <Field label={t.qtyDelta} error={err("qty_delta")}><input name="qty_delta" required inputMode="decimal" dir="ltr" placeholder="-2" className={inputClass} /></Field>
      <Field label={c.reason} error={err("reason")}><input name="reason" required className={inputClass} /></Field>
      <SubmitButton pending={pending} className="mt-6 py-2.5">{t.adjust}</SubmitButton>
      {state.error && !state.fieldErrors && <div className="md:col-span-full"><Notice tone="danger">{state.error.code === "STOCK_INSUFFICIENT" ? t.insufficient : state.error.message}</Notice></div>}
      {state.ok && <p className="text-sm font-semibold text-success md:col-span-full">{c.saved}</p>}
    </form>
  );
}

export function ReceiveForm({ lang, t, locations }: { lang: string; t: T; locations: Option[] }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(receiveStock, {});
  const errs = Object.values(state.fieldErrors ?? {}).flat();
  return (
    <form onSubmit={keepValues(action)} className="space-y-4 rounded-md border border-sand-300 bg-sand-0 p-6">
      <input type="hidden" name="lang" value={lang} />
      <p className="text-ink-muted">{t.receiveIntro}</p>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t.location}>
          <select name="location_id" className={inputClass}>{locations.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}</select>
        </Field>
        <Field label={t.note}><input name="note" className={inputClass} /></Field>
      </div>
      <Field label={t.receiveLines}>
        <textarea name="lines" required rows={10} dir="ltr" placeholder={"1R-0750 | 24\n600-211-1340 | 10\nKomatsu | 6754-71-5210 | 4"} className={`${inputClass} num`} />
      </Field>
      {state.error?.code === "PART_NOT_FOUND" && <Notice tone="danger">{t.notFoundLines} <span dir="ltr">{state.error.message}</span></Notice>}
      {state.error && state.error.code !== "PART_NOT_FOUND" && <Notice tone="danger">{errs[0] ?? state.error.message}</Notice>}
      {state.ok && <Notice tone="success">{t.received}</Notice>}
      <SubmitButton pending={pending}>{t.receive}</SubmitButton>
    </form>
  );
}

/** خطوتان: معاينة كل الصفوف بحالتها، ثم تطبيق الكل أو لا شيء. ملف فيه خطأ لا يُطبق (US-37). */
export function ImportFlow({ lang, t, c }: { lang: string; t: T; c: C }) {
  const [state, action, pending] = useActionState<ImportState, FormData>(importStep, {});
  const p = state.preview;
  const tone = { new: "var(--status-success)", update: "var(--status-progress)", error: "var(--status-negative)" } as const;

  return (
    <div className="space-y-5">
      <form action={action} className="flex flex-wrap items-end gap-3 rounded-md border border-sand-300 bg-sand-0 p-6">
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="step" value="preview" />
        <Field label={t.import}><input type="file" name="file" required accept=".xlsx,.csv" className={inputClass} /></Field>
        <SubmitButton pending={pending} className="py-2.5">{t.preview}</SubmitButton>
      </form>

      {state.error && <Notice tone="danger">{Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message}</Notice>}
      {state.applied && <Notice tone="success">{t.applied} {state.applied.created} {t.created}{listSep(lang)}{state.applied.updated} {t.updated}</Notice>}

      {p && (
        <section className="space-y-4">
          <div className="flex flex-wrap gap-3">
            {([["new", p.summary.new, t.created], ["update", p.summary.updated, t.updated], ["error", p.summary.errors, t.errors]] as const).map(([k, n, label]) => (
              <span key={k} className="rounded-sm bg-sand-0 px-4 py-2 font-semibold ring-1 ring-sand-300"><span className="num text-xl" style={{ color: tone[k] }}>{n}</span> {label}</span>
            ))}
            <span className="rounded-sm px-4 py-2 text-ink-muted">{c.total}: <span className="num">{p.summary.total}</span></span>
          </div>
          {p.summary.errors > 0 ? <Notice tone="danger">{t.fixErrors}</Notice> : p.token && (
            <form action={action}>
              <input type="hidden" name="lang" value={lang} />
              <input type="hidden" name="step" value="commit" />
              <input type="hidden" name="token" value={p.token} />
              <SubmitButton pending={pending}>{t.apply}</SubmitButton>
            </form>
          )}
          <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
            <table className="w-full min-w-[700px] text-sm">
              <thead className="bg-sand-200">
                <tr>{[t.line, "", t.partNumber, t.nameAr, t.price, t.errors].map((h, i) => <th key={i} scope="col" className="px-3 py-2 text-start">{h}</th>)}</tr>
              </thead>
              <tbody className="divide-y divide-sand-300">
                {p.rows.map((r) => (
                  <tr key={r.line} className="align-top">
                    <td className="num px-3 py-2">{r.line}</td>
                    <td className="px-3 py-2 font-semibold" style={{ color: tone[r.status] }}>{t.rowStatus[r.status]}</td>
                    <td className="num px-3 py-2" dir="ltr">{String(r.values.part_number ?? "")}</td>
                    <td className="px-3 py-2">{String(r.values.name_ar ?? "")}</td>
                    <td className="num px-3 py-2" dir="ltr">{String(r.values.price ?? "")}</td>
                    <td className="px-3 py-2 text-danger" dir="ltr">{r.errors.join("; ")}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
