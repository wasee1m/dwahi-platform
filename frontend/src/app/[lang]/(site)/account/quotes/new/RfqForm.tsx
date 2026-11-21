"use client";

import { useState, useTransition } from "react";
import { Field, inputClass, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { createRfq, findParts, uploadPrivate, type DraftItem, type PartHit, type QuoteState } from "../actions";

// C-07 طلب عرض سعر (FR-RFQ-01، FR-PRT-05، US-12): بنود من الكتالوج أو غير مدرجة بصورتها، ومواصفات السلندر
type T = AppDictionary["quotes"];
type Line = DraftItem & { key: string; label: string; photos: { id: string; name: string }[]; cylinder: boolean };
const SPECS = ["bore_mm", "rod_mm", "stroke_mm", "mounting", "pressure_bar"] as const;
const SPEC_LABEL = { bore_mm: "bore", rod_mm: "rod", stroke_mm: "stroke", mounting: "mounting", pressure_bar: "pressure" } as const;

let seq = 0;
const key = () => `l${++seq}`;

export function RfqForm({ lang, t, initialType, initial }: { lang: string; t: T; initialType: "equipment" | "parts"; initial?: { listing_id?: string; part_id?: string; label: string } }) {
  const f = t.form;
  const [type, setType] = useState(initialType);
  const [lines, setLines] = useState<Line[]>(initial ? [{ key: key(), quantity: "1", label: initial.label, photos: [], cylinder: false, listing_id: initial.listing_id, part_id: initial.part_id }] : []);
  const [query, setQuery] = useState("");
  const [hits, setHits] = useState<PartHit[] | null>(null);
  const [searching, startSearch] = useTransition();
  const [notes, setNotes] = useState("");
  const [exportTo, setExportTo] = useState("");
  const [address, setAddress] = useState("");
  const [state, setState] = useState<QuoteState>({});
  const [sending, startSend] = useTransition();
  const [uploading, setUploading] = useState<string | null>(null);

  const update = (k: string, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === k ? { ...l, ...patch } : l)));
  const err = (path: string) => state.fieldErrors?.[path]?.[0];

  function search() {
    startSearch(async () => setHits(await findParts(lang, query)));
  }
  function addPart(p: PartHit) {
    setLines((ls) => [...ls, { key: key(), part_id: p.id, quantity: "1", label: `${p.brand.name} ${p.part_number} · ${p.name}`, photos: [], cylinder: false }]);
    setHits(null);
    setQuery("");
  }
  async function addPhoto(k: string, file?: File) {
    if (!file) return;
    setUploading(k);
    const form = new FormData();
    form.set("lang", lang);
    form.set("file", file);
    const r = await uploadPrivate(form);
    setUploading(null);
    if (r.ok) setLines((ls) => ls.map((l) => (l.key === k ? { ...l, photos: [...l.photos, { id: r.id, name: r.name }] } : l)));
    else setState({ error: { code: "UPLOAD", message: r.message } });
  }
  function submit() {
    if (!lines.length) return setState({ error: { code: "NO_ITEMS", message: f.noItems } });
    startSend(async () => {
      const r = await createRfq(lang, {
        type,
        items: lines.map((l) => ({
          listing_id: l.listing_id, part_id: l.part_id, free_text: l.free_text, quantity: l.quantity,
          attachment_ids: l.photos.map((p) => p.id), specs: l.cylinder ? l.specs : undefined,
        })),
        notes, delivery_country: exportTo ? exportTo.toUpperCase() : "OM", delivery_address_text: address, attachment_ids: [],
      });
      setState(r ?? {});
    });
  }

  return (
    <div className="space-y-6">
      <p className="text-ink-muted">{f.intro}</p>

      <fieldset>
        <legend className="mb-2 text-sm font-semibold">{f.type}</legend>
        <div className="flex gap-2">
          {(["parts", "equipment"] as const).map((x) => (
            <label key={x} className={`cursor-pointer rounded-sm px-4 py-2 font-semibold ring-1 ${type === x ? "bg-night text-on-night ring-night" : "bg-sand-0 ring-sand-300"}`}>
              <input type="radio" name="type" value={x} checked={type === x} onChange={() => setType(x)} className="sr-only" /> {t.types[x]}
            </label>
          ))}
        </div>
      </fieldset>

      {type === "parts" && (
        <section className="rounded-md border border-sand-300 bg-sand-0 p-4">
          <div className="flex flex-wrap gap-2">
            <input value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); search(); } }}
              placeholder={f.searchPart} aria-label={f.searchPart} className={`${inputClass} min-w-0 flex-1`} />
            <button type="button" onClick={search} disabled={searching || query.trim().length < 2} className="rounded-sm bg-night px-4 font-bold text-on-night disabled:opacity-50">{searching ? f.searching : "⌕"}</button>
            <button type="button" onClick={() => setLines((ls) => [...ls, { key: key(), free_text: "", quantity: "1", label: "", photos: [], cylinder: false }])}
              className="rounded-sm border border-ink px-4 py-2 font-semibold">+ {f.addUnlisted}</button>
          </div>
          {hits && (
            <ul className="mt-3 divide-y divide-sand-300 rounded-sm border border-sand-300">
              {hits.length === 0 && <li className="p-3 text-sm text-ink-muted">{f.noResults}</li>}
              {hits.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 p-2">
                  <span className="text-sm"><span className="num font-semibold" dir="ltr">{p.part_number}</span> · {p.name} <span className="text-ink-muted">({p.brand.name})</span></span>
                  <button type="button" onClick={() => addPart(p)} className="rounded-sm bg-sun px-3 py-1 text-sm font-bold text-on-sun">{f.add}</button>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <ol className="space-y-3">
        {lines.map((l, i) => (
          <li key={l.key} className="rounded-md border border-sand-300 bg-sand-0 p-4">
            <div className="flex flex-wrap items-start gap-3">
              <span className="num mt-2 font-bold text-ink-muted">{i + 1}.</span>
              <div className="min-w-0 flex-1 space-y-2">
                {l.free_text !== undefined ? (
                  <Field label={f.unlisted} hint={f.unlistedHint} error={err(`items.${i}.free_text`)}>
                    <input value={l.free_text} onChange={(e) => update(l.key, { free_text: e.target.value })} className={inputClass} />
                  </Field>
                ) : (
                  <p className="pt-2 font-semibold">{l.label}{err(`items.${i}.listing_id`) && <span className="block text-sm text-danger">{err(`items.${i}.listing_id`)}</span>}</p>
                )}
                {l.free_text !== undefined && (
                  <>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      {l.photos.map((p) => <span key={p.id} className="rounded-sm bg-sand-200 px-2 py-1">📷 {p.name}</span>)}
                      <label className="cursor-pointer rounded-sm border border-ink px-3 py-1 font-semibold">
                        {uploading === l.key ? f.uploading : f.upload}
                        <input type="file" accept="image/jpeg,image/png" className="sr-only" disabled={uploading !== null} onChange={(e) => { void addPhoto(l.key, e.target.files?.[0]); e.target.value = ""; }} />
                      </label>
                    </div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={l.cylinder} onChange={(e) => update(l.key, { cylinder: e.target.checked })} className="size-4 accent-[var(--sun)]" /> {f.cylinder}</label>
                    {l.cylinder && (
                      <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        {SPECS.map((s) => (
                          <Field key={s} label={f[SPEC_LABEL[s]]} error={err(`items.${i}.specs.${s}`)}>
                            <input value={l.specs?.[s] ?? ""} inputMode={s === "mounting" ? "text" : "decimal"} dir={s === "mounting" ? undefined : "ltr"}
                              onChange={(e) => update(l.key, { specs: { ...l.specs, [s]: e.target.value } })} className={inputClass} />
                          </Field>
                        ))}
                      </div>
                    )}
                  </>
                )}
              </div>
              <Field label={f.quantity} error={err(`items.${i}.quantity`)}>
                <input value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} inputMode="decimal" dir="ltr" className={`${inputClass} num w-24`} />
              </Field>
              <button type="button" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))} className="mt-7 rounded-sm px-2 py-1 text-sm text-danger hover:bg-sand-200">{f.remove}</button>
            </div>
          </li>
        ))}
      </ol>

      <div className="grid gap-4 md:grid-cols-2">
        <div className="md:col-span-2">
          <Field label={f.notes} hint={f.notesHint} error={err("notes")}><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} className={inputClass} /></Field>
        </div>
        <Field label={f.address} error={err("delivery_address_text")}><input value={address} onChange={(e) => setAddress(e.target.value)} className={inputClass} /></Field>
        <Field label={`${f.export}: ${f.country}`} error={err("delivery_country")}>
          <input value={exportTo} onChange={(e) => setExportTo(e.target.value.replace(/[^a-z]/gi, "").slice(0, 2))} dir="ltr" placeholder={f.inOman} className={`${inputClass} uppercase`} />
        </Field>
      </div>

      {state.error && <Notice tone="danger">{state.fieldErrors ? Object.values(state.fieldErrors).flat()[0] : state.error.message}</Notice>}
      <button type="button" onClick={submit} disabled={sending || uploading !== null}
        className="rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{f.submit}</button>
    </div>
  );
}
