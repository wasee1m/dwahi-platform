"use client";

export function PrintButton({ label }: { label: string }) {
  return <button type="button" onClick={() => window.print()} className="rounded-sm bg-sun px-4 py-2 text-sm font-bold text-on-sun">{label}</button>;
}
