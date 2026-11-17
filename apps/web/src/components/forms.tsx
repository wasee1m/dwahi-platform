import type { ReactNode } from "react";

// عناصر النماذج المشتركة (06 §4.2): تسمية فوق الحقل، وخطأ تحته بلون الخطر، وحقول الأرقام باتجاه LTR
export const inputClass =
  "w-full rounded-sm border border-sand-300 bg-sand-100 px-3 py-2.5 focus:border-night aria-invalid:border-danger disabled:opacity-60";

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-semibold">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-ink-muted">{hint}</span>}
      {error && <span role="alert" className="mt-1 block text-sm text-danger">{error}</span>}
    </label>
  );
}

export function SubmitButton({ children, pending, className = "" }: { children: ReactNode; pending?: boolean; className?: string }) {
  return (
    <button disabled={pending} className={`rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep disabled:opacity-60 ${className}`}>
      {children}
    </button>
  );
}

export function Notice({ tone = "info", children }: { tone?: "info" | "success" | "danger"; children: ReactNode }) {
  const color = tone === "success" ? "text-success" : tone === "danger" ? "text-danger" : "text-info";
  return <p role={tone === "danger" ? "alert" : "status"} className={`rounded-sm bg-sand-0 px-4 py-3 text-sm font-semibold ring-1 ring-sand-300 ${color}`}>{children}</p>;
}

export function AuthCard({ title, intro, children }: { title: string; intro?: ReactNode; children: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-md px-4 py-14">
      <div className="rounded-md border border-sand-300 bg-sand-0 p-7">
        <h1 className="text-2xl">{title}</h1>
        {intro && <div className="mt-2 text-ink-muted">{intro}</div>}
        <div className="mt-6">{children}</div>
      </div>
    </div>
  );
}
