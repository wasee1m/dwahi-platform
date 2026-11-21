"use client";

import { useState, useTransition } from "react";
import { inputClass, Notice } from "@/components/forms";

// محادثة الطلب (FR-MSG-01، US-21): للعميل والموظف. المعرّف يولده المتصفح فإعادة الإرسال لا تكرر الرسالة.
export type ThreadMessage = {
  id: string; body: string | null; mine: boolean; sender: { name: string | null; is_staff: boolean };
  attachments: { id: string; name: string | null; mime_type: string; url: string | null }[]; at: string;
};
type Labels = { placeholder: string; send: string; attach: string; empty: string; team: string };
type Upload = (form: FormData) => Promise<{ ok: true; id: string; name: string } | { ok: false; message: string }>;
type Send = (id: string, body: string, attachmentIds: string[]) => Promise<{ ok: true; thread: ThreadMessage[] } | { ok: false; message: string }>;

export function Conversation({ lang, initial, labels, upload, send, staffView = false }: {
  lang: string; initial: ThreadMessage[]; labels: Labels; upload: Upload; send: Send; staffView?: boolean;
}) {
  const [thread, setThread] = useState(initial);
  const [body, setBody] = useState("");
  const [files, setFiles] = useState<{ id: string; name: string }[]>([]);
  const [error, setError] = useState("");
  const [busy, start] = useTransition();
  const when = new Intl.DateTimeFormat(lang === "ar" ? "ar-OM" : "en-GB", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  function submit() {
    if (!body.trim() && !files.length) return;
    const id = crypto.randomUUID();
    start(async () => {
      const r = await send(id, body.trim(), files.map((f) => f.id));
      if (r.ok) { setThread(r.thread); setBody(""); setFiles([]); setError(""); } else setError(r.message);
    });
  }
  async function attach(file?: File) {
    if (!file) return;
    const f = new FormData();
    f.set("lang", lang);
    f.set("file", file);
    const r = await upload(f);
    if (r.ok) setFiles((x) => [...x, { id: r.id, name: r.name }]); else setError(r.message);
  }

  return (
    <div className="space-y-3">
      {thread.length === 0 ? <p className="text-sm text-ink-muted">{labels.empty}</p> : (
        <ol className="max-h-[28rem] space-y-3 overflow-y-auto rounded-md bg-sand-100 p-3">
          {thread.map((m) => (
            <li key={m.id} className={`flex ${m.mine ? "justify-start" : "justify-end"}`}>
              <div className={`max-w-[80%] rounded-md px-3 py-2 ${m.mine ? "bg-sand-0 ring-1 ring-sand-300" : "bg-night text-on-night"}`}>
                <p className="text-xs opacity-75">{m.sender.is_staff && !staffView ? labels.team : m.sender.name} · {when.format(new Date(m.at))}</p>
                {m.body && <p className="mt-1 whitespace-pre-line">{m.body}</p>}
                {m.attachments.map((a) => a.url && (
                  a.mime_type.startsWith("image/")
                    // eslint-disable-next-line @next/next/no-img-element -- رابط موقّع مؤقت من الـ API، لا يُحسَّن
                    ? <a key={a.id} href={a.url} target="_blank" rel="noreferrer"><img src={a.url} alt={a.name ?? ""} className="mt-2 max-h-48 rounded-sm" /></a>
                    : <a key={a.id} href={a.url} target="_blank" rel="noreferrer" className="mt-2 block underline">📎 {a.name}</a>
                ))}
              </div>
            </li>
          ))}
        </ol>
      )}
      <div className="space-y-2">
        <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={2} placeholder={labels.placeholder} aria-label={labels.placeholder} className={inputClass} />
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" onClick={submit} disabled={busy} className="rounded-sm bg-sun px-5 py-2 font-bold text-on-sun disabled:opacity-60">{labels.send}</button>
          <label className="cursor-pointer rounded-sm border border-ink px-3 py-2 text-sm font-semibold">
            {labels.attach}
            <input type="file" accept="image/jpeg,image/png,application/pdf" className="sr-only" onChange={(e) => { void attach(e.target.files?.[0]); e.target.value = ""; }} />
          </label>
          {files.map((f) => <span key={f.id} className="rounded-sm bg-sand-200 px-2 py-1 text-sm">📎 {f.name}</span>)}
        </div>
        {error && <Notice tone="danger">{error}</Notice>}
      </div>
    </div>
  );
}
