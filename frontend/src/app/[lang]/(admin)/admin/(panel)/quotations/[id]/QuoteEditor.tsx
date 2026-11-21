"use client";

import { useState, useTransition } from "react";
import { Field, inputClass, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../../actions";
import { discardQuote, saveQuote, searchCatalog, sendQuote, type CatalogHit, type Line, type QuoteTerms } from "../../rfq-actions";

// A-06 محرر عرض السعر (FR-RFQ-02، US-32). الحساب هنا للعرض أثناء الكتابة بنفس قاعدة BR-01 (بيسات صحيحة، نصف للأعلى)،
// والقيم المعتمدة يحسبها الخادم عند الحفظ.
type T = AppDictionary["admin"]["quote"];
type Row = Line & { key: string };
const TYPES = ["equipment", "part", "labor", "service_fee", "other"] as const;

// BigInt() لا الحرفية (0n): هدف TypeScript هنا ES2017
const ZERO = BigInt(0);
const [HALF_CENT, CENT, HALF_PCT, PCT] = [BigInt(50), BigInt(100), BigInt(5000), BigInt(10000)];
const units = (v: string | number | null | undefined, scale: number) => BigInt(Math.round(Number(v || 0) * 10 ** scale));
const baisa = (b: bigint) => {
  const neg = b < ZERO;
  const s = (neg ? -b : b).toString().padStart(4, "0");
  return `${neg ? "-" : ""}${s.slice(0, -3).replace(/\B(?=(\d{3})+(?!\d))/g, ",")}.${s.slice(-3)}`;
};
function calc(l: Line) {
  const gross = (units(l.quantity, 2) * units(l.unit_price, 3) + HALF_CENT) / CENT;
  const sub = gross - units(l.discount_amount, 3);
  const vat = sub > ZERO ? (sub * units(l.vat_rate, 2) + HALF_PCT) / PCT : ZERO;
  return { gross, sub, vat, total: sub + vat };
}

let seq = 0;
const k = () => `r${++seq}`;

export function QuoteEditor({ lang, t, quotationId, rfqId, initialLines, initialTerms, defaultVat }: {
  lang: string; t: T; quotationId: string; rfqId: string; initialLines: Line[]; initialTerms: QuoteTerms; defaultVat: string;
}) {
  const [rows, setRows] = useState<Row[]>(initialLines.map((l) => ({ ...l, key: k() })));
  const [terms, setTerms] = useState(initialTerms);
  const [state, setState] = useState<AdminState>({});
  const [busy, start] = useTransition();
  const [q, setQ] = useState("");
  const [hits, setHits] = useState<CatalogHit[] | null>(null);

  const set = (key: string, patch: Partial<Line>) => setRows((rs) => rs.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  const blank = (type: Line["item_type"]): Row => ({ key: k(), item_type: type, description_ar: "", description_en: "", quantity: "1", unit: type === "labor" ? "job" : "piece", unit_price: "0", discount_amount: "0", vat_rate: defaultVat });
  const err = (path: string) => state.fieldErrors?.[path]?.[0];
  const totals = rows.reduce((acc, r) => { const c = calc(r); return { gross: acc.gross + c.gross, disc: acc.disc + units(r.discount_amount, 3), vat: acc.vat + c.vat }; }, { gross: ZERO, disc: ZERO, vat: ZERO });
  const payload = (): Line[] => rows.map((r) => ({
    item_type: r.item_type, listing_id: r.listing_id ?? null, part_id: r.part_id ?? null, description_ar: r.description_ar, description_en: r.description_en,
    quantity: r.quantity, unit: r.unit, unit_price: r.unit_price, discount_amount: r.discount_amount || "0", vat_rate: r.vat_rate, warranty_months: r.warranty_months || null,
  }));

  const save = (then?: () => Promise<AdminState | void>) => start(async () => {
    const r = await saveQuote(lang, quotationId, payload(), terms);
    setState(r);
    if (r.ok && then) {
      const next = await then();
      if (next) setState(next);
    }
  });

  return (
    <div className="space-y-6">
      <section className="space-y-3 rounded-md border border-sand-300 bg-sand-0 p-4">
        <div className="flex flex-wrap gap-2">
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t.searchCatalog} aria-label={t.searchCatalog}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); start(async () => setHits(await searchCatalog(lang, q))); } }}
            className={`${inputClass} min-w-0 flex-1`} />
          <button type="button" onClick={() => start(async () => setHits(await searchCatalog(lang, q)))} className="rounded-sm bg-night px-4 font-bold text-on-night">⌕</button>
          <button type="button" onClick={() => setRows((rs) => [...rs, blank("labor")])} className="rounded-sm border border-ink px-3 py-2 font-semibold">+ {t.addLabor}</button>
          <button type="button" onClick={() => setRows((rs) => [...rs, blank("other")])} className="rounded-sm border border-ink px-3 py-2 font-semibold">+ {t.addLine}</button>
        </div>
        {hits && (
          <ul className="divide-y divide-sand-300 rounded-sm border border-sand-300">
            {hits.length === 0 && <li className="p-2 text-sm text-ink-muted">—</li>}
            {hits.map((h, i) => (
              <li key={i}>
                <button type="button" className="block w-full p-2 text-start text-sm hover:bg-sand-100"
                  onClick={() => { setRows((rs) => [...rs, { ...h, key: k(), quantity: "1", discount_amount: "0", vat_rate: h.vat_rate ?? defaultVat }]); setHits(null); setQ(""); }}>
                  {t.itemTypes[h.item_type as (typeof TYPES)[number]]} · {h.label} · <span className="num">{h.unit_price}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
        <table className="w-full min-w-[1100px] text-sm">
          <thead className="bg-sand-200">
            <tr>{["#", t.type, t.descAr, t.descEn, t.qty, t.unit, t.price, t.discount, t.vat, t.warranty, t.lineTotal, ""].map((h, i) => <th key={i} scope="col" className="px-2 py-2 text-start">{h}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-sand-300 align-top">
            {rows.map((r, i) => {
              const c = calc(r);
              const cell = "w-full rounded-sm border border-sand-300 bg-sand-100 px-2 py-1.5";
              return (
                <tr key={r.key}>
                  <td className="num px-2 py-2">{i + 1}</td>
                  <td className="px-2 py-2">
                    <select value={r.item_type} onChange={(e) => set(r.key, { item_type: e.target.value })} disabled={Boolean(r.part_id || r.listing_id)} aria-label={t.type} className={cell}>
                      {TYPES.map((x) => <option key={x} value={x}>{t.itemTypes[x]}</option>)}
                    </select>
                  </td>
                  <td className="px-2 py-2 min-w-48"><textarea rows={2} value={r.description_ar} onChange={(e) => set(r.key, { description_ar: e.target.value })} aria-label={t.descAr} className={cell} />
                    {err(`items.${i}.description_ar`) && <span className="text-xs text-danger">{err(`items.${i}.description_ar`)}</span>}</td>
                  <td className="px-2 py-2 min-w-48"><textarea rows={2} dir="ltr" value={r.description_en} onChange={(e) => set(r.key, { description_en: e.target.value })} aria-label={t.descEn} className={cell} />
                    {err(`items.${i}.description_en`) && <span className="text-xs text-danger">{err(`items.${i}.description_en`)}</span>}</td>
                  <td className="px-2 py-2 w-20"><input value={r.quantity} onChange={(e) => set(r.key, { quantity: e.target.value })} inputMode="decimal" dir="ltr" aria-label={t.qty} className={`${cell} num`} /></td>
                  <td className="px-2 py-2 w-20"><input value={r.unit} onChange={(e) => set(r.key, { unit: e.target.value })} dir="ltr" aria-label={t.unit} className={cell} /></td>
                  <td className="px-2 py-2 w-28"><input value={r.unit_price} onChange={(e) => set(r.key, { unit_price: e.target.value })} inputMode="decimal" dir="ltr" aria-label={t.price} className={`${cell} num`} /></td>
                  <td className="px-2 py-2 w-24"><input value={r.discount_amount} onChange={(e) => set(r.key, { discount_amount: e.target.value })} inputMode="decimal" dir="ltr" aria-label={t.discount} className={`${cell} num`} />
                    {err(`items.${i}.discount_amount`) && <span className="text-xs text-danger">{err(`items.${i}.discount_amount`)}</span>}</td>
                  <td className="px-2 py-2 w-16"><input value={r.vat_rate} onChange={(e) => set(r.key, { vat_rate: e.target.value })} inputMode="decimal" dir="ltr" aria-label={t.vat} className={`${cell} num`} /></td>
                  <td className="px-2 py-2 w-16"><input value={r.warranty_months ?? ""} onChange={(e) => set(r.key, { warranty_months: e.target.value ? Number(e.target.value) : null })} inputMode="numeric" dir="ltr" aria-label={t.warranty} className={`${cell} num`} /></td>
                  <td className="num px-2 py-2 whitespace-nowrap font-semibold">{baisa(c.total)}<span className="block text-xs font-normal text-ink-muted">+{baisa(c.vat)}</span></td>
                  <td className="px-2 py-2"><button type="button" onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))} aria-label="✕" className="rounded-sm px-2 py-1 text-danger hover:bg-sand-200">✕</button></td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <dl className="ms-auto w-full max-w-sm space-y-1 rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
        <div className="flex justify-between"><dt>{t.subtotal}</dt><dd className="num">{baisa(totals.gross)}</dd></div>
        <div className="flex justify-between"><dt>{t.discountTotal}</dt><dd className="num">− {baisa(totals.disc)}</dd></div>
        <div className="flex justify-between"><dt>{t.vatTotal}</dt><dd className="num">{baisa(totals.vat)}</dd></div>
        <div className="flex justify-between border-t border-ink pt-1 text-base font-bold"><dt>{t.total}</dt><dd className="num">{baisa(totals.gross - totals.disc + totals.vat)}</dd></div>
        <p className="pt-1 text-xs text-ink-muted">{t.computedHint}</p>
      </dl>

      <section className="grid gap-4 rounded-md border border-sand-300 bg-sand-0 p-4 md:grid-cols-2">
        <Field label={t.validUntil} error={err("valid_until")}><input type="date" value={terms.valid_until ?? ""} onChange={(e) => setTerms({ ...terms, valid_until: e.target.value || null })} className={inputClass} /></Field>
        <Field label={t.duration}><input value={terms.estimated_duration ?? ""} onChange={(e) => setTerms({ ...terms, estimated_duration: e.target.value || null })} className={inputClass} /></Field>
        {([["delivery_terms", t.delivery, t.tpl.delivery], ["warranty_terms", t.warranty_terms, t.tpl.warranty], ["payment_terms", t.payment, t.tpl.payment]] as const).map(([key, label, tpl]) => (
          <Field key={key} label={label}>
            <textarea rows={2} value={terms[key] ?? ""} onChange={(e) => setTerms({ ...terms, [key]: e.target.value || null })} className={inputClass} />
            <button type="button" onClick={() => setTerms({ ...terms, [key]: tpl })} className="mt-1 text-xs font-semibold underline">{t.templates}</button>
          </Field>
        ))}
      </section>

      {state.error && <Notice tone="danger">{state.fieldErrors ? Object.values(state.fieldErrors).flat()[0] : state.error.message}</Notice>}
      {state.ok && <Notice tone="success">{t.saved}</Notice>}
      <div className="flex flex-wrap items-center gap-3">
        <button type="button" disabled={busy} onClick={() => save()} className="rounded-sm border border-ink px-5 py-2.5 font-bold disabled:opacity-60">{t.save}</button>
        <button type="button" disabled={busy} onClick={() => save(async () => { window.open(`/${lang}/admin/quotations/${quotationId}/pdf`, "_blank"); })}
          className="rounded-sm border border-ink px-5 py-2.5 font-bold disabled:opacity-60">{t.preview} ↗</button>
        <button type="button" disabled={busy || rows.length === 0} onClick={() => { if (window.confirm(t.sendConfirm)) save(() => sendQuote(lang, quotationId, rfqId)); }}
          className="rounded-sm bg-sun px-6 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{t.send}</button>
        <button type="button" disabled={busy} onClick={() => start(async () => { const r = await discardQuote(lang, quotationId, rfqId); if (r) setState(r); })}
          className="ms-auto rounded-sm px-4 py-2.5 font-semibold text-danger hover:bg-sand-200">{t.discard}</button>
      </div>
    </div>
  );
}
