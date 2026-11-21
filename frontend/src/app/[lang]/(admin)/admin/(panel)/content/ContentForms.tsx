"use client";

import Image from "next/image";
import { useActionState, useState, useTransition } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import { Markdown } from "@/components/Markdown";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { uploadPhoto } from "../catalog-actions";
import { orderFaqs, saveBanner, saveFaq, savePage } from "../content-actions";

// A-14: الصفحات بمعاينة حية، والأسئلة الشائعة بترتيبها، والبنرات بصورتيها وفترتها (US-42)
type T = AppDictionary["admin"]["content"];
type C = AppDictionary["admin"]["common"];
export type PageDoc = {
  id: string; slug: string; title_ar: string; title_en: string; body_ar: string; body_en: string; status: "draft" | "published";
  seo_title_ar: string | null; seo_title_en: string | null; seo_description_ar: string | null; seo_description_en: string | null;
};
export type Faq = { id: string; section: "equipment" | "parts" | "service"; question_ar: string; question_en: string; answer_ar: string; answer_en: string; sort_order: number; is_active: boolean };
export type Banner = {
  id: string; placement: "web_home" | "app_home" | "parts" | "service"; image_ar_id: string; image_en_id: string; image_ar_url: string | null; image_en_url: string | null;
  link: string | null; starts_at: string; ends_at: string; sort_order: number; is_active: boolean; state: "live" | "scheduled" | "ended" | "inactive";
};

const SECTIONS = ["equipment", "parts", "service"] as const;
const PLACEMENTS = ["web_home", "app_home", "parts", "service"] as const;

/** UTC ← → حقل datetime-local بتوقيت مسقط (UTC+4 دون توقيت صيفي) */
const toMuscat = (iso?: string) => (iso ? new Date(Date.parse(iso) + 4 * 3600_000).toISOString().slice(0, 16) : "");

function useSave(fn: (prev: AdminState, form: FormData) => Promise<AdminState>) {
  const [state, action, pending] = useActionState<AdminState, FormData>(fn, {});
  return { state, pending, onSubmit: keepValues(action), err: (k: string) => state.fieldErrors?.[k]?.[0] };
}

function Result({ state, c }: { state: AdminState; c: C }) {
  if (state.ok) return <span className="text-sm font-semibold text-success">{c.saved}</span>;
  if (state.error) return <Notice tone="danger">{state.fieldErrors ? c.errorSave : state.error.message}</Notice>;
  return null;
}

export function PageEditor({ lang, t, c, page }: { lang: string; t: T; c: C; page?: PageDoc }) {
  const { state, pending, onSubmit, err } = useSave(savePage);
  const [tab, setTab] = useState<"ar" | "en">("ar");
  const [body, setBody] = useState({ ar: page?.body_ar ?? "", en: page?.body_en ?? "" });
  const [preview, setPreview] = useState(false);

  return (
    <form onSubmit={onSubmit} className="space-y-5 rounded-md border border-sand-300 bg-sand-0 p-6">
      <input type="hidden" name="lang" value={lang} />
      {page && <input type="hidden" name="id" value={page.id} />}
      <div className="grid gap-4 md:grid-cols-3">
        {page ? (
          <Field label={t.slug}><input value={page.slug} disabled dir="ltr" className={inputClass} /></Field>
        ) : (
          <Field label={t.slug} hint={t.slugHint} error={err("slug")}><input name="slug" required dir="ltr" pattern="[a-z0-9]+(-[a-z0-9]+)*" className={inputClass} /></Field>
        )}
        <Field label={t.titleAr} error={err("title_ar")}><input name="title_ar" required defaultValue={page?.title_ar} className={inputClass} /></Field>
        <Field label={t.titleEn} error={err("title_en")}><input name="title_en" required dir="ltr" defaultValue={page?.title_en} className={inputClass} /></Field>
      </div>

      <div>
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-sand-300">
          <div className="flex">
            {(["ar", "en"] as const).map((x) => (
              <button key={x} type="button" onClick={() => setTab(x)}
                className={`-mb-px border-b-2 px-4 py-2 font-semibold ${tab === x ? "border-sun" : "border-transparent text-ink-muted"}`}>
                {x === "ar" ? t.bodyAr : t.bodyEn}{err(`body_${x}`) && <span className="text-danger"> •</span>}
              </button>
            ))}
          </div>
          <label className="flex items-center gap-2 pb-1 text-sm"><input type="checkbox" checked={preview} onChange={(e) => setPreview(e.target.checked)} className="size-4 accent-[var(--sun)]" /> {t.preview}</label>
        </div>
        {(["ar", "en"] as const).map((x) => (
          <div key={x} className={`mt-3 grid gap-4 ${preview ? "lg:grid-cols-2" : ""} ${tab === x ? "" : "hidden"}`}>
            <Field label={x === "ar" ? t.bodyAr : t.bodyEn} hint={t.bodyHint} error={err(`body_${x}`)}>
              <textarea name={`body_${x}`} dir={x === "ar" ? "rtl" : "ltr"} rows={18} value={body[x]} onChange={(e) => setBody((b) => ({ ...b, [x]: e.target.value }))}
                required={x === "ar"} className={`${inputClass} font-mono text-sm`} />
            </Field>
            {preview && <div dir={x === "ar" ? "rtl" : "ltr"} className="rounded-sm border border-dashed border-sand-300 p-4"><Markdown source={body[x]} /></div>}
          </div>
        ))}
      </div>

      <details className="rounded-sm border border-sand-300 p-4">
        <summary className="cursor-pointer font-semibold">{t.seo}</summary>
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <Field label={t.seoTitleAr} error={err("seo_title_ar")}><input name="seo_title_ar" defaultValue={page?.seo_title_ar ?? ""} className={inputClass} /></Field>
          <Field label={t.seoTitleEn} error={err("seo_title_en")}><input name="seo_title_en" dir="ltr" defaultValue={page?.seo_title_en ?? ""} className={inputClass} /></Field>
          <Field label={t.seoDescAr} error={err("seo_description_ar")}><textarea name="seo_description_ar" rows={2} defaultValue={page?.seo_description_ar ?? ""} className={inputClass} /></Field>
          <Field label={t.seoDescEn} error={err("seo_description_en")}><textarea name="seo_description_en" dir="ltr" rows={2} defaultValue={page?.seo_description_en ?? ""} className={inputClass} /></Field>
        </div>
      </details>

      <div className="flex flex-wrap items-end gap-4">
        <Field label={t.status} hint={t.publishRule}>
          <select name="status" defaultValue={page?.status ?? "draft"} className={inputClass}>
            {(["draft", "published"] as const).map((s) => <option key={s} value={s}>{t.statuses[s]}</option>)}
          </select>
        </Field>
        <SubmitButton pending={pending}>{c.save}</SubmitButton>
        <Result state={state} c={c} />
      </div>
    </form>
  );
}

export function FaqForm({ lang, t, c, faq }: { lang: string; t: T; c: C; faq?: Faq }) {
  const { state, pending, onSubmit, err } = useSave(saveFaq);
  return (
    <form onSubmit={onSubmit} className="grid gap-3 md:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      {faq && <input type="hidden" name="id" value={faq.id} />}
      <Field label={t.section} error={err("section")}>
        <select name="section" defaultValue={faq?.section ?? "equipment"} className={inputClass}>{SECTIONS.map((s) => <option key={s} value={s}>{t.sections[s]}</option>)}</select>
      </Field>
      <label className="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="is_active" defaultChecked={faq?.is_active ?? true} className="size-4 accent-[var(--sun)]" /> {t.active}</label>
      <Field label={t.questionAr} error={err("question_ar")}><input name="question_ar" required defaultValue={faq?.question_ar} className={inputClass} /></Field>
      <Field label={t.questionEn} error={err("question_en")}><input name="question_en" required dir="ltr" defaultValue={faq?.question_en} className={inputClass} /></Field>
      <Field label={t.answerAr} error={err("answer_ar")}><textarea name="answer_ar" required rows={3} defaultValue={faq?.answer_ar} className={inputClass} /></Field>
      <Field label={t.answerEn} error={err("answer_en")}><textarea name="answer_en" required rows={3} dir="ltr" defaultValue={faq?.answer_en} className={inputClass} /></Field>
      <div className="flex items-center gap-3 md:col-span-2"><SubmitButton pending={pending} className="py-2">{c.save}</SubmitButton><Result state={state} c={c} /></div>
    </form>
  );
}

/** ترتيب الأسئلة داخل كل قسم بأزرار أعلى/أسفل، ثم حفظ الترتيب كله */
export function FaqOrder({ lang, t, c, faqs }: { lang: string; t: T; c: C; faqs: Faq[] }) {
  const [list, setList] = useState(faqs);
  const [state, setState] = useState<AdminState>({});
  const [saving, start] = useTransition();
  const move = (id: string, d: -1 | 1) => setList((all) => {
    const f = all.find((x) => x.id === id)!;
    const sec = all.filter((x) => x.section === f.section);
    const i = sec.indexOf(f);
    const j = i + d;
    if (j < 0 || j >= sec.length) return all;
    [sec[i], sec[j]] = [sec[j], sec[i]];
    return [...all.filter((x) => x.section !== f.section), ...sec].sort((a, b) => SECTIONS.indexOf(a.section) - SECTIONS.indexOf(b.section));
  });
  const display = (q: Faq) => (lang === "ar" ? q.question_ar : q.question_en);

  return (
    <div className="space-y-4">
      {SECTIONS.map((s) => {
        const sec = list.filter((x) => x.section === s);
        if (!sec.length) return null;
        return (
          <section key={s} className="rounded-md border border-sand-300 bg-sand-0 p-4">
            <h3 className="mb-2 font-bold">{t.sections[s]}</h3>
            <ol className="space-y-1">
              {sec.map((q, i) => (
                <li key={q.id} className="flex items-center justify-between gap-3 rounded-sm px-2 py-1 hover:bg-sand-100">
                  <span className={q.is_active ? "" : "text-ink-muted line-through"}><span className="num me-2 text-ink-muted">{i + 1}.</span>{display(q)}</span>
                  <span className="flex shrink-0 gap-1">
                    <button type="button" disabled={i === 0} onClick={() => move(q.id, -1)} aria-label={c.up} className="rounded-sm px-2 py-1 hover:bg-sand-200 disabled:opacity-30">↑</button>
                    <button type="button" disabled={i === sec.length - 1} onClick={() => move(q.id, 1)} aria-label={c.down} className="rounded-sm px-2 py-1 hover:bg-sand-200 disabled:opacity-30">↓</button>
                  </span>
                </li>
              ))}
            </ol>
          </section>
        );
      })}
      <div className="flex items-center gap-3">
        <button type="button" disabled={saving} onClick={() => start(async () => setState(await orderFaqs(lang, list.map((x) => x.id))))}
          className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60">{t.saveOrder}</button>
        <Result state={state} c={c} />
      </div>
    </div>
  );
}

function ImageField({ lang, label, name, initial, c, t, error }: { lang: string; label: string; name: string; initial?: { id: string; url: string | null }; c: C; t: T; error?: string }) {
  const [img, setImg] = useState(initial?.id ? initial : undefined);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  async function pick(file?: File) {
    if (!file) return;
    setBusy(true);
    const f = new FormData();
    f.set("lang", lang);
    f.set("file", file);
    const r = await uploadPhoto(f);
    setBusy(false);
    if (r.ok) { setImg({ id: r.media.id, url: r.media.url }); setMsg(""); } else setMsg(r.message);
  }
  return (
    <div>
      <span className="mb-1 block text-sm font-semibold">{label}</span>
      <input type="hidden" name={name} value={img?.id ?? ""} />
      <div className="flex items-center gap-3">
        {img?.url ? <Image src={img.url} alt="" width={240} height={80} unoptimized className="h-16 w-48 rounded-sm object-cover ring-1 ring-sand-300" /> : <span className="block h-16 w-48 rounded-sm bg-sand-200" />}
        <label className="cursor-pointer rounded-sm border border-ink px-3 py-1.5 text-sm font-semibold">
          {busy ? c.uploading : t.upload}
          <input type="file" accept="image/jpeg,image/png" className="sr-only" disabled={busy} onChange={(e) => { void pick(e.target.files?.[0]); e.target.value = ""; }} />
        </label>
      </div>
      {(msg || error) && <p className="mt-1 text-sm text-danger">{msg || error}</p>}
    </div>
  );
}

export function BannerForm({ lang, t, c, banner }: { lang: string; t: T; c: C; banner?: Banner }) {
  const { state, pending, onSubmit, err } = useSave(saveBanner);
  return (
    <form onSubmit={onSubmit} className="grid gap-4 md:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      {banner && <input type="hidden" name="id" value={banner.id} />}
      <ImageField lang={lang} label={t.imageAr} name="image_ar_id" initial={banner && { id: banner.image_ar_id, url: banner.image_ar_url }} c={c} t={t} error={err("image_ar_id")} />
      <ImageField lang={lang} label={t.imageEn} name="image_en_id" initial={banner && { id: banner.image_en_id, url: banner.image_en_url }} c={c} t={t} error={err("image_en_id")} />
      <Field label={t.placement} error={err("placement")}>
        <select name="placement" defaultValue={banner?.placement ?? "web_home"} className={inputClass}>{PLACEMENTS.map((p) => <option key={p} value={p}>{t.placements[p]}</option>)}</select>
      </Field>
      <Field label={t.link} hint={t.linkHint} error={err("link")}><input name="link" dir="ltr" defaultValue={banner?.link ?? ""} placeholder="/ar/parts" className={inputClass} /></Field>
      <Field label={`${t.startsAt} (${t.timeHint})`} error={err("starts_at")}><input name="starts_at" type="datetime-local" required defaultValue={toMuscat(banner?.starts_at)} className={inputClass} /></Field>
      <Field label={`${t.endsAt} (${t.timeHint})`} error={err("ends_at")}><input name="ends_at" type="datetime-local" required defaultValue={toMuscat(banner?.ends_at)} className={inputClass} /></Field>
      <Field label={t.sort} error={err("sort_order")}><input name="sort_order" type="number" min={0} dir="ltr" defaultValue={banner?.sort_order ?? 0} className={inputClass} /></Field>
      <label className="flex items-center gap-2 self-end pb-3 text-sm"><input type="checkbox" name="is_active" defaultChecked={banner?.is_active ?? true} className="size-4 accent-[var(--sun)]" /> {t.active}</label>
      <div className="flex items-center gap-3 md:col-span-2"><SubmitButton pending={pending} className="py-2">{c.save}</SubmitButton><Result state={state} c={c} /></div>
    </form>
  );
}
