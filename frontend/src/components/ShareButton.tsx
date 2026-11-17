"use client";

import { useState } from "react";

// مشاركة (W-03): مشاركة الجوال الأصلية إن وُجدت، وإلا نسخ الرابط
export function ShareButton({ label, copiedLabel, title }: { label: string; copiedLabel: string; title: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <button
      type="button"
      onClick={async () => {
        const url = window.location.href;
        if (navigator.share) {
          await navigator.share({ title, url }).catch(() => {});
        } else {
          await navigator.clipboard.writeText(url);
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
        }
      }}
      className="inline-flex items-center justify-center rounded-sm border border-ink px-5 py-3 font-bold hover:bg-sand-200"
    >
      {copied ? copiedLabel : label}
    </button>
  );
}
