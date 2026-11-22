"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, keepValues, Notice, SubmitButton } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Address } from "@/lib/account-types";
import { saveAddress, type PortalState } from "../portal-actions";

export function AddressForm({ lang, t, governorates, organizationId, address }: {
  lang: string; t: AppDictionary["addresses"]; governorates: AppDictionary["onboarding"]["governorates"]; organizationId: string | null; address?: Address;
}) {
  const [state, action, pending] = useActionState<PortalState, FormData>(saveAddress, {});
  const [pin, setPin] = useState({ lat: String(address?.latitude ?? ""), lng: String(address?.longitude ?? "") });
  const [locating, setLocating] = useState<"idle" | "busy" | "failed">("idle");
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  function locate() {
    if (!navigator.geolocation) return setLocating("failed");
    setLocating("busy");
    navigator.geolocation.getCurrentPosition(
      (p) => {
        setPin({ lat: p.coords.latitude.toFixed(6), lng: p.coords.longitude.toFixed(6) });
        setLocating("idle");
      },
      () => setLocating("failed"),
      { enableHighAccuracy: true, timeout: 15_000 },
    );
  }

  return (
    <form action={action} onSubmit={keepValues(action)} className="grid gap-4 sm:grid-cols-2">
      <input type="hidden" name="lang" value={lang} />
      {address ? <input type="hidden" name="id" value={address.id} /> : <input type="hidden" name="organization_id" value={organizationId ?? ""} />}
      <Field label={t.label} hint={t.labelHint} error={err("label")}><input name="label" required maxLength={100} defaultValue={address?.label} className={inputClass} /></Field>
      <Field label={t.kind} error={err("kind")}>
        <select name="kind" defaultValue={address?.kind ?? "delivery"} className={inputClass}>
          {(["delivery", "site", "both"] as const).map((k) => <option key={k} value={k}>{t.kinds[k]}</option>)}
        </select>
      </Field>
      <Field label={t.governorate} error={err("governorate")}>
        <select name="governorate" required defaultValue={address?.governorate ?? ""} className={inputClass}>
          <option value="" disabled>—</option>
          {Object.entries(governorates).map(([k, name]) => <option key={k} value={k}>{name}</option>)}
        </select>
      </Field>
      <Field label={t.wilayat} error={err("wilayat")}><input name="wilayat" required maxLength={50} defaultValue={address?.wilayat} className={inputClass} /></Field>
      <div className="sm:col-span-2">
        <Field label={t.description} error={err("description")}><textarea name="description" rows={2} maxLength={1000} defaultValue={address?.description ?? ""} className={inputClass} /></Field>
      </div>

      <fieldset className="sm:col-span-2">
        <legend className="mb-1 text-sm font-semibold">{t.pin}</legend>
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" onClick={locate} disabled={locating === "busy"} className="rounded-sm border border-ink px-3 py-2 text-sm font-semibold">
            {locating === "busy" ? t.locating : t.useLocation}
          </button>
          <input name="latitude" aria-label={t.latitude} placeholder={t.latitude} dir="ltr" inputMode="decimal" value={pin.lat}
            onChange={(e) => setPin({ ...pin, lat: e.target.value })} className={`${inputClass} num max-w-36`} />
          <input name="longitude" aria-label={t.longitude} placeholder={t.longitude} dir="ltr" inputMode="decimal" value={pin.lng}
            onChange={(e) => setPin({ ...pin, lng: e.target.value })} className={`${inputClass} num max-w-36`} />
          {pin.lat && pin.lng && (
            <a href={`https://www.google.com/maps?q=${encodeURIComponent(`${pin.lat},${pin.lng}`)}`} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold underline">{t.openMap}</a>
          )}
        </div>
        <p className="mt-1 text-xs text-ink-muted">{locating === "failed" ? t.locationFailed : t.pinHint}</p>
        {(err("latitude") ?? err("longitude")) && <p role="alert" className="mt-1 text-sm text-danger">{err("latitude") ?? err("longitude")}</p>}
      </fieldset>

      <Field label={t.contactName} error={err("contact_name")}><input name="contact_name" maxLength={120} defaultValue={address?.contact_name ?? ""} className={inputClass} /></Field>
      <Field label={t.contactPhone} error={err("contact_phone")}><input name="contact_phone" type="tel" dir="ltr" defaultValue={address?.contact_phone ?? ""} className={inputClass} /></Field>
      <div className="space-y-2 sm:col-span-2">
        {state.ok && <Notice tone="success">{t.saved}</Notice>}
        {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
        <SubmitButton pending={pending}>{address ? t.save : t.add}</SubmitButton>
      </div>
    </form>
  );
}
