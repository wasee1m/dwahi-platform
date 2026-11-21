"use client";

import { useActionState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { AdminState } from "../../actions";
import { saveBranch, saveSettings } from "../settings-actions";

// A-18: حقل لكل نوع إعداد (SettingsRegistry في الخادم)، وتبويب كامل يُحفظ مرة واحدة (US-40)
type T = AppDictionary["admin"]["settings"];
type C = AppDictionary["admin"]["common"];
type Gov = AppDictionary["onboarding"]["governorates"];
export type SettingRow = {
  key: string; group: string; type: string; rule: string | null; value: unknown; default: unknown;
  options: string[] | null; readonly: boolean; updated_at: string | null; updated_by: string | null;
};
export type Branch = {
  id: string; code: string; name_ar: string; name_en: string; governorate: string; wilayat: string; address_ar: string | null; address_en: string | null;
  phone: string | null; whatsapp_sales: string | null; whatsapp_service: string | null; whatsapp_parts: string | null; email: string | null;
  working_hours: Record<string, { open: string; close: string } | null>; covered_governorates: string[]; is_active: boolean; latitude: number; longitude: number;
};

const DAYS = ["sat", "sun", "mon", "tue", "wed", "thu", "fri"] as const;
const COMPANY = ["name_ar", "name_en", "cr_number", "vat_number", "address_ar", "address_en", "phone", "email"] as const;
const LTR = new Set(["name_en", "cr_number", "vat_number", "address_en", "phone", "email"]);
const check = "size-4 accent-[var(--sun)]";

function SettingInput({ s, t, gov, err }: { s: SettingRow; t: T; gov: Gov; err: (k: string) => string | undefined }) {
  const v = s.value as never;
  const key = s.key;
  const ltrNum = `${inputClass} num`;
  switch (s.type) {
    case "days": case "count":
      return <input name={key} type="number" min={0} dir="ltr" required defaultValue={v} className={`${ltrNum} max-w-40`} />;
    case "money": case "percent":
      return <input name={key} inputMode="decimal" dir="ltr" defaultValue={v ?? ""} placeholder={s.value === null ? t.notSet : ""} className={`${ltrNum} max-w-48`} />;
    case "enum":
      return <select name={key} defaultValue={v} className={`${inputClass} max-w-64`}>{s.options?.map((o) => <option key={o} value={o}>{t.displays[o as keyof T["displays"]] ?? o}</option>)}</select>;
    case "bool":
      return <input type="checkbox" name={key} defaultChecked={Boolean(v)} className={check} />;
    case "thresholds": {
      const x = v as { limited: number; available: number };
      return (
        <div className="flex flex-wrap gap-3">
          <Field label={t.limited} error={err(`${key}.limited`)}><input name={`${key}.limited`} type="number" min={1} dir="ltr" defaultValue={x.limited} className={`${ltrNum} w-32`} /></Field>
          <Field label={t.available} error={err(`${key}.available`)}><input name={`${key}.available`} type="number" min={1} dir="ltr" defaultValue={x.available} className={`${ltrNum} w-32`} /></Field>
        </div>
      );
    }
    case "sla": {
      const x = v as { normal_working_hours: number; urgent_hours: number };
      return (
        <div className="flex flex-wrap gap-3">
          <Field label={t.normalHours} error={err(`${key}.normal_working_hours`)}><input name={`${key}.normal_working_hours`} type="number" min={1} dir="ltr" defaultValue={x.normal_working_hours} className={`${ltrNum} w-32`} /></Field>
          <Field label={t.urgentHours} error={err(`${key}.urgent_hours`)}><input name={`${key}.urgent_hours`} type="number" min={1} dir="ltr" defaultValue={x.urgent_hours} className={`${ltrNum} w-32`} /></Field>
        </div>
      );
    }
    case "business_hours": {
      const x = v as { days: string[]; start: string; end: string };
      return (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2">
            {DAYS.map((d) => (
              <label key={d} className="flex items-center gap-1.5 rounded-sm bg-sand-100 px-2.5 py-1.5 text-sm">
                <input type="checkbox" name={`${key}.days`} value={d} defaultChecked={x.days.includes(d)} className={check} /> {t.days[d]}
              </label>
            ))}
          </div>
          <div className="flex flex-wrap gap-3">
            <Field label={t.start} error={err(`${key}.start`)}><input name={`${key}.start`} type="time" dir="ltr" defaultValue={x.start} className={`${ltrNum} w-36`} /></Field>
            <Field label={t.end} error={err(`${key}.end`)}><input name={`${key}.end`} type="time" dir="ltr" defaultValue={x.end} className={`${ltrNum} w-36`} /></Field>
          </div>
        </div>
      );
    }
    case "company": {
      const x = v as Record<string, string>;
      return (
        <div className="grid gap-3 md:grid-cols-2">
          {COMPANY.map((f) => (
            <Field key={f} label={t.company[f]} error={err(`${key}.${f}`)}>
              <input name={`${key}.${f}`} dir={LTR.has(f) ? "ltr" : undefined} required={f === "name_ar" || f === "name_en"} defaultValue={x[f] ?? ""} className={inputClass} />
            </Field>
          ))}
        </div>
      );
    }
    case "governorate_fees": {
      const x = v as Record<string, string | null>;
      return (
        <div>
          <p className="mb-2 text-xs text-ink-muted">{t.noDelivery}</p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {Object.keys(x).map((g) => (
              <Field key={g} label={gov[g as keyof Gov] ?? g} error={err(`${key}.${g}`)}>
                <input type="hidden" name={`${key}._keys`} value={g} />
                <input name={`${key}.${g}`} inputMode="decimal" dir="ltr" defaultValue={x[g] ?? ""} className={ltrNum} />
              </Field>
            ))}
          </div>
        </div>
      );
    }
    case "list":
      return <p className="num flex flex-wrap gap-2" dir="ltr">{(v as string[]).map((p) => <span key={p} className="rounded-sm bg-sand-200 px-2 py-1">{p}</span>)}</p>;
    default:
      return null;
  }
}

export function SettingsGroupForm({ lang, t, c, gov, rows, when }: { lang: string; t: T; c: C; gov: Gov; rows: SettingRow[]; when: Record<string, string> }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(saveSettings, {});
  const editable = rows.filter((r) => !r.readonly);
  // أخطاء الـ API بالمسار values.<key> أو values.<key>.<field>
  const err = (path: string) => state.fieldErrors?.[`values.${path}`]?.[0];

  return (
    <form onSubmit={keepValues(action)} className="space-y-4">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="_types" value={JSON.stringify(Object.fromEntries(editable.map((r) => [r.key, r.type])))} />
      {rows.map((s) => (
        <section key={s.key} className="rounded-md border border-sand-300 bg-sand-0 p-5">
          <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h3 className="font-bold">
              {s.type === "bool" ? (
                <label className="flex items-center gap-2"><SettingInput s={s} t={t} gov={gov} err={err} /> {t.keys[s.key as keyof T["keys"]] ?? s.key}</label>
              ) : (t.keys[s.key as keyof T["keys"]] ?? s.key)}
            </h3>
            <span className="text-xs text-ink-muted">
              {s.rule && <span className="num me-2" dir="ltr">{s.rule}</span>}
              {s.updated_at && <>{t.lastChange}: {when[s.key]}{s.updated_by ? ` · ${s.updated_by}` : ""}</>}
            </span>
          </div>
          {s.type !== "bool" && <SettingInput s={s} t={t} gov={gov} err={err} />}
          {s.readonly && <p className="mt-2 text-sm text-ink-muted">{t.readonly}</p>}
          {err(s.key) && <p role="alert" className="mt-2 text-sm text-danger">{err(s.key)}</p>}
        </section>
      ))}
      {editable.length > 0 && (
        <div className="flex flex-wrap items-center gap-3">
          <SubmitButton pending={pending}>{c.save}</SubmitButton>
          {state.ok && <span className="text-sm font-semibold text-success">{t.saved}</span>}
          {state.error && <Notice tone="danger">{state.fieldErrors ? c.errorSave : state.error.message}</Notice>}
        </div>
      )}
    </form>
  );
}

export function BranchForm({ lang, t, c, gov, branch }: { lang: string; t: T; c: C; gov: Gov; branch?: Branch }) {
  const [state, action, pending] = useActionState<AdminState, FormData>(saveBranch, {});
  const b = t.branch;
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  const hoursErr = Object.entries(state.fieldErrors ?? {}).find(([k]) => k.startsWith("working_hours"))?.[1][0];
  const govs = Object.keys(gov) as (keyof Gov)[];
  const text = (name: keyof Branch, label: string, ltr = false, required = false) => (
    <Field label={label} error={err(name)}>
      <input name={name} dir={ltr ? "ltr" : undefined} required={required} defaultValue={(branch?.[name] as string | null) ?? ""} className={inputClass} />
    </Field>
  );

  return (
    <form onSubmit={keepValues(action)} className="space-y-5">
      <input type="hidden" name="lang" value={lang} />
      {branch && <input type="hidden" name="id" value={branch.id} />}
      <div className="grid gap-4 md:grid-cols-3">
        {branch ? (
          <Field label={b.code}><input value={branch.code} disabled dir="ltr" className={inputClass} /></Field>
        ) : (
          <Field label={b.code} hint={b.codeHint} error={err("code")}><input name="code" required dir="ltr" pattern="[A-Za-z0-9]{2,20}" className={`${inputClass} uppercase`} /></Field>
        )}
        {text("name_ar", b.nameAr, false, true)}
        {text("name_en", b.nameEn, true, true)}
        <Field label={b.governorate} error={err("governorate")}>
          <select name="governorate" defaultValue={branch?.governorate ?? "north_batinah"} className={inputClass}>{govs.map((g) => <option key={g} value={g}>{gov[g]}</option>)}</select>
        </Field>
        {text("wilayat", b.wilayat, true, true)}
        {text("email", b.email, true)}
        {text("address_ar", b.addressAr)}
        {text("address_en", b.addressEn, true)}
        <div className="grid grid-cols-2 gap-3">
          <Field label={b.latitude} error={err("latitude")}><input name="latitude" required inputMode="decimal" dir="ltr" defaultValue={branch?.latitude ?? ""} placeholder="24.347" className={`${inputClass} num`} /></Field>
          <Field label={b.longitude} error={err("longitude")}><input name="longitude" required inputMode="decimal" dir="ltr" defaultValue={branch?.longitude ?? ""} placeholder="56.709" className={`${inputClass} num`} /></Field>
        </div>
      </div>
      <p className="text-xs text-ink-muted">{b.mapHint}</p>

      <div className="grid gap-4 md:grid-cols-4">
        {text("phone", b.phone, true)}
        {text("whatsapp_sales", b.waSales, true)}
        {text("whatsapp_service", b.waService, true)}
        {text("whatsapp_parts", b.waParts, true)}
      </div>

      <fieldset>
        <legend className="mb-2 text-sm font-semibold">{b.hours}</legend>
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {DAYS.map((d) => {
            const h = branch ? branch.working_hours[d] : ["fri", "sat"].includes(d) ? null : { open: "08:00", close: "17:00" };
            return (
              <div key={d} className="rounded-sm bg-sand-100 p-2">
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name={`open_${d}`} defaultChecked={Boolean(h)} className={check} /> {t.days[d]}</label>
                <div className="mt-1 flex items-center gap-1">
                  <input type="time" name={`${d}.open`} dir="ltr" defaultValue={h?.open ?? "08:00"} aria-label={`${t.days[d]} ${t.start}`} className="num w-full rounded-sm border border-sand-300 px-1 py-1 text-sm" />
                  <span aria-hidden>–</span>
                  <input type="time" name={`${d}.close`} dir="ltr" defaultValue={h?.close ?? "17:00"} aria-label={`${t.days[d]} ${t.end}`} className="num w-full rounded-sm border border-sand-300 px-1 py-1 text-sm" />
                </div>
              </div>
            );
          })}
        </div>
        {hoursErr && <p className="mt-1 text-sm text-danger">{hoursErr}</p>}
      </fieldset>

      <fieldset>
        <legend className="mb-2 text-sm font-semibold">{b.covered}</legend>
        <div className="flex flex-wrap gap-2">
          {govs.map((g) => (
            <label key={g} className="flex items-center gap-1.5 rounded-sm bg-sand-100 px-2.5 py-1.5 text-sm">
              <input type="checkbox" name="covered_governorates" value={g} defaultChecked={branch?.covered_governorates.includes(g)} className={check} /> {gov[g]}
            </label>
          ))}
        </div>
        {err("covered_governorates") && <p className="mt-1 text-sm text-danger">{err("covered_governorates")}</p>}
      </fieldset>

      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" defaultChecked={branch?.is_active ?? true} className={check} /> {b.active}</label>
      <div className="flex flex-wrap items-center gap-3">
        <SubmitButton pending={pending}>{c.save}</SubmitButton>
        {state.ok && <span className="text-sm font-semibold text-success">{c.saved}</span>}
        {state.error && <Notice tone="danger">{state.fieldErrors ? c.errorSave : state.error.message}</Notice>}
      </div>
    </form>
  );
}
