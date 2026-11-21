"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { saveBrand, saveCategory, saveModel } from "../catalog-actions";

// A-03: نموذج واحد للإضافة والتعديل لكل من الماركة والفئة والموديل (FR-CAT-06)
type T = AppDictionary["admin"]["catalogSetup"];
type C = AppDictionary["admin"]["common"];
export type Brand = { id: string; name: string; slug: string; is_represented: boolean; sort_order: number; is_active: boolean; logo_url: string | null };
export type Category = { id: string; parent_id: string | null; name_ar: string; name_en: string; slug: string; sort_order: number };
export type Model = {
  id: string; brand_id: string; category_id: string; model_name: string; slug: string; brand: string; category: string;
  operating_weight_kg: number | null; engine_power_kw: string | null; bucket_capacity_m3: string | null; lifting_capacity_kg: number | null;
  specs: { label_ar: string; label_en: string; value: string; unit: string | null }[] | null;
};

function useSave(fn: (prev: AdminState, form: FormData) => Promise<AdminState>) {
  const [state, action, pending] = useActionState<AdminState, FormData>(fn, {});
  return { state, pending, onSubmit: keepValues(action), err: (k: string) => state.fieldErrors?.[k]?.[0] };
}

function Footer({ state, pending, c }: { state: AdminState; pending: boolean; c: C }) {
  return (
    <div className="flex flex-wrap items-center gap-3 md:col-span-full">
      <SubmitButton pending={pending} className="py-2">{c.save}</SubmitButton>
      {state.ok && <span className="text-sm font-semibold text-success">{c.saved}</span>}
      {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
    </div>
  );
}

const check = "flex items-center gap-2 self-end pb-3 text-sm";

export function BrandForm({ lang, t, c, brand }: { lang: string; t: T; c: C; brand?: Brand }) {
  const { state, pending, onSubmit, err } = useSave(saveBrand);
  return (
    <form onSubmit={onSubmit} className="grid gap-3 md:grid-cols-5">
      <input type="hidden" name="lang" value={lang} />
      {brand && <input type="hidden" name="id" value={brand.id} />}
      <Field label={t.name} error={err("name")}><input name="name" required dir="ltr" defaultValue={brand?.name} className={inputClass} /></Field>
      <Field label={t.slug} error={err("slug")}><input name="slug" dir="ltr" defaultValue={brand?.slug} className={inputClass} /></Field>
      <Field label={t.sort} error={err("sort_order")}><input name="sort_order" type="number" min={0} dir="ltr" defaultValue={brand?.sort_order ?? 0} className={inputClass} /></Field>
      <label className={check}><input type="checkbox" name="is_represented" defaultChecked={brand?.is_represented} className="size-4 accent-[var(--sun)]" /> {t.represented}</label>
      <label className={check}><input type="checkbox" name="is_active" defaultChecked={brand?.is_active ?? true} className="size-4 accent-[var(--sun)]" /> {t.active}</label>
      <Footer state={state} pending={pending} c={c} />
    </form>
  );
}

export function CategoryForm({ lang, t, c, category, categories }: { lang: string; t: T; c: C; category?: Category; categories: Category[] }) {
  const { state, pending, onSubmit, err } = useSave(saveCategory);
  return (
    <form onSubmit={onSubmit} className="grid gap-3 md:grid-cols-5">
      <input type="hidden" name="lang" value={lang} />
      {category && <input type="hidden" name="id" value={category.id} />}
      <Field label={t.nameAr} error={err("name_ar")}><input name="name_ar" required defaultValue={category?.name_ar} className={inputClass} /></Field>
      <Field label={t.nameEn} error={err("name_en")}><input name="name_en" required dir="ltr" defaultValue={category?.name_en} className={inputClass} /></Field>
      <Field label={t.slug} error={err("slug")}><input name="slug" dir="ltr" defaultValue={category?.slug} className={inputClass} /></Field>
      <Field label={t.parent} error={err("parent_id")}>
        <select name="parent_id" defaultValue={category?.parent_id ?? ""} className={inputClass}>
          <option value="">{t.noParent}</option>
          {categories.filter((x) => x.id !== category?.id).map((x) => <option key={x.id} value={x.id}>{lang === "ar" ? x.name_ar : x.name_en}</option>)}
        </select>
      </Field>
      <Field label={t.sort} error={err("sort_order")}><input name="sort_order" type="number" min={0} dir="ltr" defaultValue={category?.sort_order ?? 0} className={inputClass} /></Field>
      <Footer state={state} pending={pending} c={c} />
    </form>
  );
}

export function ModelForm({ lang, t, c, model, brands, categories }: { lang: string; t: T; c: C; model?: Model; brands: Brand[]; categories: Category[] }) {
  const { state, pending, onSubmit, err } = useSave(saveModel);
  const specErrors = Object.entries(state.fieldErrors ?? {}).filter(([k]) => k.startsWith("specs")).flatMap(([, v]) => v);
  const specs = (model?.specs ?? []).map((s) => [s.label_ar, s.label_en, s.value, s.unit ?? ""].join(" | ")).join("\n");
  return (
    <form onSubmit={onSubmit} className="grid gap-3 md:grid-cols-4">
      <input type="hidden" name="lang" value={lang} />
      {model && <input type="hidden" name="id" value={model.id} />}
      <Field label={t.brand} error={err("brand_id")}>
        <select name="brand_id" required defaultValue={model?.brand_id ?? ""} className={inputClass}>
          <option value="" disabled>—</option>
          {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
      </Field>
      <Field label={t.category} error={err("category_id")}>
        <select name="category_id" required defaultValue={model?.category_id ?? ""} className={inputClass}>
          <option value="" disabled>—</option>
          {categories.map((x) => <option key={x.id} value={x.id}>{lang === "ar" ? x.name_ar : x.name_en}</option>)}
        </select>
      </Field>
      <Field label={t.modelName} error={err("model_name")}><input name="model_name" required dir="ltr" defaultValue={model?.model_name} className={inputClass} /></Field>
      <Field label={t.weight} error={err("operating_weight_kg")}><input name="operating_weight_kg" type="number" min={0} dir="ltr" defaultValue={model?.operating_weight_kg ?? ""} className={inputClass} /></Field>
      <Field label={t.power} error={err("engine_power_kw")}><input name="engine_power_kw" inputMode="decimal" dir="ltr" defaultValue={model?.engine_power_kw ?? ""} className={inputClass} /></Field>
      <Field label={t.bucket} error={err("bucket_capacity_m3")}><input name="bucket_capacity_m3" inputMode="decimal" dir="ltr" defaultValue={model?.bucket_capacity_m3 ?? ""} className={inputClass} /></Field>
      <Field label={t.lifting} error={err("lifting_capacity_kg")}><input name="lifting_capacity_kg" type="number" min={0} dir="ltr" defaultValue={model?.lifting_capacity_kg ?? ""} className={inputClass} /></Field>
      <div className="md:col-span-full">
        <Field label={t.extraSpecs} hint={t.specLine} error={specErrors[0]}>
          <textarea name="specs" rows={3} defaultValue={specs} placeholder="سعة الخزان | Fuel tank | 410 | L" className={inputClass} />
        </Field>
      </div>
      <Footer state={state} pending={pending} c={c} />
    </form>
  );
}
