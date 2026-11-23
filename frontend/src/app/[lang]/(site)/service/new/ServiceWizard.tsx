"use client";

import { useRef, useState, useTransition } from "react";
import { Field, inputClass, Notice } from "@/components/forms";
import type { AppDictionary } from "@/i18n/get-dictionary";
import { formatMoney } from "@/lib/format";
import type { ServiceMeta } from "@/lib/service-types";
import type { Locale } from "@/i18n/config";
import { uploadPrivate } from "../../account/quotes/actions";
import { createServiceRequest, type ServiceState } from "../../service-actions";

type T = AppDictionary["service"];
type Machine = { id: string; name: string; serial_number: string | null };
type Media = { id: string; name: string; kind: "photo" | "video" };
type Subject = "fleet" | "new" | "component";

/** C-15: أربع خطوات. المرفقات تُرفع فور اختيارها، والطلب لا يُرسل قبل اكتمالها. */
export function ServiceWizard({ lang, t, components, meta, machines, organizationId, accountName, preselected }: {
  lang: Locale; t: T; components: Record<string, string>; meta: ServiceMeta; machines: Machine[];
  organizationId: string | null; accountName: string; preselected: string | null;
}) {
  const [step, setStep] = useState(1);
  const [subject, setSubject] = useState<Subject>(machines.length ? "fleet" : "new");
  const [machine, setMachine] = useState(preselected && machines.some((m) => m.id === preselected) ? preselected : machines[0]?.id ?? "");
  const [newMachine, setNewMachine] = useState({ brand_text: "", model_text: "", serial_number: "" });
  const [component, setComponent] = useState(meta.components[0] ?? "hydraulic_cylinder");
  const [componentDetails, setComponentDetails] = useState("");
  const [problem, setProblem] = useState("");
  const [media, setMedia] = useState<Media[]>([]);
  const [voice, setVoice] = useState<{ id: string; url: string } | null>(null);
  const [uploading, setUploading] = useState(0);
  const [uploadError, setUploadError] = useState("");
  const [urgency, setUrgency] = useState<"normal" | "urgent">("normal");
  const [preferred, setPreferred] = useState("");
  const [ack, setAck] = useState(false);
  const [state, setState] = useState<ServiceState>({});
  const [pending, start] = useTransition();
  const recorder = useRef<MediaRecorder | null>(null);
  const [recording, setRecording] = useState(false);

  async function upload(file: File): Promise<string | null> {
    setUploading((n) => n + 1);
    setUploadError("");
    const form = new FormData();
    form.set("file", file);
    form.set("lang", lang);
    const r = await uploadPrivate(form);
    setUploading((n) => n - 1);
    if (!r.ok) {
      setUploadError(r.message);
      return null;
    }
    return r.id;
  }

  async function addFiles(files: FileList | null) {
    for (const f of Array.from(files ?? [])) {
      const kind = f.type.startsWith("video/") ? "video" : "photo";
      const id = await upload(f);
      if (id) setMedia((m) => [...m, { id, name: f.name, kind }]);
    }
  }

  async function toggleVoice() {
    if (recording) {
      recorder.current?.stop();
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const rec = new MediaRecorder(stream);
      const chunks: Blob[] = [];
      rec.ondataavailable = (e) => chunks.push(e.data);
      rec.onstop = async () => {
        stream.getTracks().forEach((tr) => tr.stop());
        setRecording(false);
        const blob = new Blob(chunks, { type: rec.mimeType || "audio/webm" });
        const id = await upload(new File([blob], `voice.${blob.type.includes("mp4") ? "m4a" : "webm"}`, { type: blob.type }));
        if (id) setVoice({ id, url: URL.createObjectURL(blob) });
      };
      recorder.current = rec;
      rec.start();
      setRecording(true);
    } catch {
      setUploadError(t.micDenied);
    }
  }

  const subjectOk = subject === "fleet" ? !!machine : subject === "new" ? newMachine.model_text.trim() !== "" : !!component;
  const problemOk = problem.trim() !== "" || !!voice;
  const err = (k: string) => state.fieldErrors?.[k]?.[0];
  const expected = new Intl.DateTimeFormat(lang === "ar" ? "ar-OM" : "en-GB", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat", numberingSystem: "latn" })
    .format(new Date(meta.expected[urgency]));
  const subjectLabel = subject === "fleet" ? machines.find((m) => m.id === machine)?.name
    : subject === "new" ? `${newMachine.brand_text} ${newMachine.model_text}`.trim() : components[component];

  function submit() {
    start(async () => {
      const r = await createServiceRequest(lang, {
        organization_id: organizationId,
        customer_equipment_id: subject === "fleet" ? machine : null,
        new_equipment: subject === "new" ? newMachine : null,
        component_type: subject === "component" ? component : null,
        component_details: subject === "component" ? componentDetails || null : null,
        problem_description: problem.trim() || null,
        voice_attachment_id: voice?.id ?? null,
        attachments: media.map(({ id, kind }) => ({ id, kind })),
        urgency, preferred_date: preferred || null, fees_acknowledged: ack,
      });
      setState(r);
    });
  }

  const steps = [t.step1, t.step2, t.step3, t.step4];
  const box = "rounded-md border border-sand-300 bg-sand-0 p-5";
  const next = "rounded-sm bg-night px-5 py-2.5 font-bold text-on-night disabled:opacity-50";
  const back = "rounded-sm px-4 py-2.5 font-semibold text-ink-muted";

  return (
    <div className="mt-6 max-w-2xl space-y-5">
      <p className="text-sm text-ink-muted">{t.account}: <b className="text-ink">{accountName}</b></p>
      <ol className="flex flex-wrap gap-2 text-sm">
        {steps.map((s, n) => (
          <li key={s} aria-current={step === n + 1 ? "step" : undefined}
            className={`rounded-[999px] px-3 py-1 font-semibold ${step === n + 1 ? "bg-sun text-on-sun" : step > n + 1 ? "bg-sand-200" : "bg-sand-100 text-ink-muted"}`}>
            {n + 1}. {s}
          </li>
        ))}
      </ol>

      {step === 1 && (
        <section className={`${box} space-y-4`}>
          <fieldset className="space-y-2">
            <legend className="font-bold">{t.whatToService}</legend>
            {(["fleet", "new", "component"] as const).map((k) => (
              <label key={k} className="flex items-center gap-2">
                <input type="radio" name="subject" checked={subject === k} onChange={() => setSubject(k)} disabled={k === "fleet" && !machines.length} className="size-4" />
                {t.subjects[k]}{k === "fleet" && !machines.length && <span className="text-sm text-ink-muted"> ({t.noFleet})</span>}
              </label>
            ))}
          </fieldset>
          {subject === "fleet" && (
            <Field label={t.machine} error={err("customer_equipment_id")}>
              <select value={machine} onChange={(e) => setMachine(e.target.value)} className={inputClass}>
                {machines.map((m) => <option key={m.id} value={m.id}>{m.name}{m.serial_number ? ` · ${m.serial_number}` : ""}</option>)}
              </select>
            </Field>
          )}
          {subject === "new" && (
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label={t.brand}><input value={newMachine.brand_text} onChange={(e) => setNewMachine({ ...newMachine, brand_text: e.target.value })} maxLength={100} className={inputClass} /></Field>
              <Field label={t.model} error={err("new_equipment.model_text")}><input value={newMachine.model_text} onChange={(e) => setNewMachine({ ...newMachine, model_text: e.target.value })} maxLength={100} className={inputClass} /></Field>
              <Field label={t.serial}><input value={newMachine.serial_number} dir="ltr" onChange={(e) => setNewMachine({ ...newMachine, serial_number: e.target.value })} maxLength={100} className={inputClass} /></Field>
              <p className="text-xs text-ink-muted sm:col-span-3">{t.addedToFleet}</p>
            </div>
          )}
          {subject === "component" && (
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t.componentType}>
                <select value={component} onChange={(e) => setComponent(e.target.value)} className={inputClass}>
                  {meta.components.map((c) => <option key={c} value={c}>{components[c]}</option>)}
                </select>
              </Field>
              <Field label={t.componentDetails} hint={t.componentHint}>
                <input value={componentDetails} onChange={(e) => setComponentDetails(e.target.value)} maxLength={1000} className={inputClass} />
              </Field>
            </div>
          )}
          <button disabled={!subjectOk} onClick={() => setStep(2)} className={next}>{t.next}</button>
        </section>
      )}

      {step === 2 && (
        <section className={`${box} space-y-4`}>
          <Field label={t.problem} hint={t.problemHint} error={err("problem_description")}>
            <textarea value={problem} onChange={(e) => setProblem(e.target.value)} rows={4} maxLength={4000} className={inputClass} />
          </Field>
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" onClick={toggleVoice} className={`rounded-sm px-4 py-2 text-sm font-semibold ${recording ? "bg-danger text-white" : "border border-ink"}`}>
              {recording ? t.stopRecording : voice ? t.recordAgain : t.recordVoice}
            </button>
            {voice && <audio controls src={voice.url} className="h-10" />}
          </div>
          <div>
            <p className="font-semibold">{t.media}</p>
            <input type="file" accept="image/*,video/*" multiple onChange={(e) => { void addFiles(e.target.files); e.target.value = ""; }} className="mt-1 block text-sm" />
            <p className="mt-1 text-xs text-ink-muted">{t.mediaHint}</p>
            {media.length > 0 && (
              <ul className="mt-2 flex flex-wrap gap-2 text-sm">
                {media.map((m) => (
                  <li key={m.id} className="flex items-center gap-2 rounded-sm bg-sand-200 px-2 py-1">
                    {m.kind === "video" ? "🎥" : "📷"} {m.name}
                    <button type="button" aria-label={t.remove} onClick={() => setMedia((x) => x.filter((y) => y.id !== m.id))} className="text-danger">×</button>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {uploading > 0 && <p role="status" className="text-sm text-ink-muted">{t.uploading}</p>}
          {uploadError && <Notice tone="danger">{uploadError}</Notice>}
          <div className="flex gap-2">
            <button onClick={() => setStep(1)} className={back}>{t.back}</button>
            <button disabled={!problemOk || uploading > 0} onClick={() => setStep(3)} className={next}>{t.next}</button>
          </div>
        </section>
      )}

      {step === 3 && (
        <section className={`${box} space-y-4`}>
          <fieldset className="space-y-2">
            <legend className="font-bold">{t.urgency}</legend>
            {(["normal", "urgent"] as const).map((u) => (
              <label key={u} className="flex items-start gap-2">
                <input type="radio" name="urgency" checked={urgency === u} onChange={() => setUrgency(u)} className="mt-1 size-4" />
                <span><b>{t.urgencies[u]}</b><span className="block text-sm text-ink-muted">
                  {u === "normal" ? t.normalHint.replace("{n}", String(meta.sla.normal_working_hours)) : t.urgentHint.replace("{n}", String(meta.sla.urgent_hours))}
                </span></span>
              </label>
            ))}
          </fieldset>
          <Field label={t.preferredDate} hint={t.preferredHint} error={err("preferred_date")}>
            <input type="date" value={preferred} min={new Date().toISOString().slice(0, 10)} onChange={(e) => setPreferred(e.target.value)} className={inputClass} />
          </Field>
          {meta.workshop && (
            <div className="rounded-sm bg-sand-100 p-3 text-sm">
              <p className="font-bold">{t.workshop}: {meta.workshop.name}</p>
              {meta.workshop.address && <p>{meta.workshop.address}</p>}
            </div>
          )}
          <div className="flex gap-2">
            <button onClick={() => setStep(2)} className={back}>{t.back}</button>
            <button onClick={() => setStep(4)} className={next}>{t.next}</button>
          </div>
        </section>
      )}

      {step === 4 && (
        <section className={`${box} space-y-4`}>
          <dl className="space-y-1 text-sm">
            <div><dt className="inline font-semibold">{t.item}: </dt><dd className="inline">{subjectLabel}</dd></div>
            <div><dt className="inline font-semibold">{t.problem}: </dt><dd className="inline">{problem || t.voiceOnly}</dd></div>
            <div><dt className="inline font-semibold">{t.media}: </dt><dd className="inline num">{media.length}</dd></div>
            <div><dt className="inline font-semibold">{t.urgency}: </dt><dd className="inline">{t.urgencies[urgency]}</dd></div>
            {preferred && <div><dt className="inline font-semibold">{t.preferredDate}: </dt><dd className="inline num">{preferred}</dd></div>}
          </dl>
          <div className="rounded-sm bg-sand-100 p-3 text-sm">
            <p className="font-bold">{meta.inspection_fee ? `${t.inspectionFee}: ${formatMoney(meta.inspection_fee, lang)}` : t.noInspectionFee}</p>
            <p className="mt-1 text-ink-muted">{t.feeRule}</p>
            <p className="mt-1">{t.expectedReply}: <b>{expected}</b></p>
          </div>
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="mt-0.5 size-4" /> {t.acknowledge}
          </label>
          {state.error && <Notice tone="danger">{Object.values(state.fieldErrors ?? {}).flat()[0] ?? state.error.message}</Notice>}
          <div className="flex gap-2">
            <button onClick={() => setStep(3)} className={back}>{t.back}</button>
            <button disabled={!ack || pending} onClick={submit} className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-50">{t.submit}</button>
          </div>
        </section>
      )}
    </div>
  );
}
