"use client";

import { useActionState, useState } from "react";
import { Field, inputClass, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { OrderLine } from "@/lib/order-types";
import { uploadPrivate } from "../../quotes/actions";
import { orderAction, type OrderState } from "../../../order-actions";

type T = AppDictionary["orders"];

export function CancelOrder({ lang, t, order }: { lang: string; t: T; order: string }) {
  const [state, action, pending] = useActionState<OrderState, FormData>(orderAction, {});
  const [asking, setAsking] = useState(false);
  return (
    <form action={action} className="rounded-md border border-sand-300 bg-sand-0 p-4 text-sm">
      <input type="hidden" name="lang" value={lang} />
      <input type="hidden" name="order" value={order} />
      <input type="hidden" name="op" value="cancel" />
      {!asking ? (
        <button type="button" onClick={() => setAsking(true)} className="font-semibold text-danger hover:underline">{t.cancel}</button>
      ) : (
        <div className="space-y-2">
          <p>{t.cancelQ}</p>
          <div className="flex gap-2">
            <button disabled={pending} className="rounded-sm px-3 py-1.5 font-semibold text-danger ring-1 ring-danger disabled:opacity-60">{t.yes}</button>
            <button type="button" onClick={() => setAsking(false)} className="px-3 py-1.5 text-ink-muted">{t.no}</button>
          </div>
        </div>
      )}
      {state.error && <p role="alert" className="mt-2 text-danger">{state.error.message}</p>}
    </form>
  );
}

/** 3b.1: البنود القابلة للإرجاع فقط وبكمية لا تتجاوز المتبقي، مع السبب والصور (BR-12) */
export function ReturnForm({ lang, t, order, items, until }: { lang: string; t: T; order: string; items: OrderLine[]; until: string }) {
  const [state, action, pending] = useActionState<OrderState, FormData>(orderAction, {});
  const [photos, setPhotos] = useState<{ id: string; name: string }[]>([]);
  const [uploading, setUploading] = useState(false);
  const returnable = items.filter((i) => Number(i.returnable_quantity) > 0);
  const err = (k: string) => state.fieldErrors?.[k]?.[0];

  async function upload(file: File) {
    setUploading(true);
    const form = new FormData();
    form.set("file", file);
    form.set("lang", lang);
    const r = await uploadPrivate(form);
    setUploading(false);
    if (r.ok) setPhotos((p) => [...p, { id: r.id, name: r.name }]);
  }

  if (state.ok) return <Notice tone="success">{t.returnSent}</Notice>;

  return (
    <section className="rounded-md border border-sand-300 bg-sand-0 p-5">
      <h2 className="text-lg">{t.returnTitle}</h2>
      <p className="mt-1 text-sm text-ink-muted">{t.returnUntil.replace("{date}", until)}</p>
      <form action={action} className="mt-4 space-y-4">
        <input type="hidden" name="lang" value={lang} />
        <input type="hidden" name="order" value={order} />
        <input type="hidden" name="op" value="return" />
        {photos.map((p) => <input key={p.id} type="hidden" name="attachment_id" value={p.id} />)}
        <ul className="space-y-2">
          {returnable.map((i) => (
            <li key={i.id} className="flex flex-wrap items-center gap-3 text-sm">
              <label className="flex flex-1 items-center gap-2">
                <input type="checkbox" name="item" value={i.id} className="size-4" />
                <span><span className="num" dir="ltr">{i.part_number}</span> · {i.name}</span>
              </label>
              <label className="flex items-center gap-2">
                <span className="text-ink-muted">{t.qty}</span>
                <input name={`qty_${i.id}`} type="number" min={1} max={Number(i.returnable_quantity)} step={1} defaultValue={1} dir="ltr"
                  className="num w-16 rounded-sm border border-sand-300 bg-sand-100 px-2 py-1 text-center" />
              </label>
            </li>
          ))}
        </ul>
        <Field label={t.returnReason} error={err("reason")}>
          <textarea name="reason" required minLength={3} maxLength={1000} rows={2} className={inputClass} />
        </Field>
        <div className="text-sm">
          <p className="font-semibold">{t.returnPhotos}</p>
          <input type="file" accept="image/*" disabled={uploading || photos.length >= 6} onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = ""; }}
            className="mt-1 block text-sm" />
          {photos.length > 0 && <p className="mt-1 text-ink-muted">{photos.map((p) => p.name).join("، ")}</p>}
        </div>
        {state.error && !state.fieldErrors && <Notice tone="danger">{state.error.message}</Notice>}
        {state.fieldErrors && !err("reason") && <Notice tone="danger">{Object.values(state.fieldErrors).flat()[0]}</Notice>}
        <button disabled={pending || uploading} className="rounded-sm bg-night px-5 py-2.5 font-bold text-on-night disabled:opacity-60">{t.sendReturn}</button>
      </form>
    </section>
  );
}
