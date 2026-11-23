"use client";

import { useState } from "react";

/**
 * عمود يومي لسلسلة واحدة (A-01): لون واحد، وأعمدة رفيعة بنهاية مدوّرة على خط الأساس، وتلميح عند المرور أو اللمس
 * بالتاريخ والقيمة، وجدول للقراءة والوصول (NFR-15). العنوان يسمّي السلسلة فلا مفتاح.
 */
export function DailyBars({ title, points, kind, dayLabel, tableLabel, lang }: {
  title: string; points: { date: string; value: number }[]; kind: "count" | "money"; dayLabel: string; tableLabel: string; lang: string;
}) {
  const nf = new Intl.NumberFormat(lang === "en" ? "en-US" : "ar-OM-u-nu-latn", kind === "money" ? { minimumFractionDigits: 3, maximumFractionDigits: 3 } : {});
  const format = (v: number) => nf.format(v);
  const [active, setActive] = useState<number | null>(null);
  const max = Math.max(1, ...points.map((p) => p.value));
  const w = 640;
  const h = 160;
  const slot = w / Math.max(1, points.length);
  const bar = Math.max(2, Math.min(18, slot - 2));
  const day = new Intl.DateTimeFormat(lang === "en" ? "en-GB" : "ar-OM-u-nu-latn", { day: "numeric", month: "short" });
  const hovered = active === null ? null : points[active];
  const total = points.reduce((a, p) => a + p.value, 0);

  return (
    <figure className="rounded-md border border-sand-300 bg-sand-0 p-4">
      <figcaption className="flex items-baseline justify-between gap-2 text-sm font-semibold">
        <span>{title}</span>
        <span className="num text-ink-muted" aria-live="polite">{hovered ? `${day.format(new Date(hovered.date))}: ${format(hovered.value)}` : format(total)}</span>
      </figcaption>
      {/* محور الزمن من اليسار لليمين في اللغتين، كالأرقام */}
      <div dir="ltr">
      <svg viewBox={`0 0 ${w} ${h + 20}`} className="mt-2 w-full" role="img" aria-label={`${title}: ${format(total)}`} onMouseLeave={() => setActive(null)}>
        <line x1={0} x2={w} y1={h} y2={h} className="stroke-sand-300" strokeWidth={1} />
        {points.map((p, i) => {
          const bh = p.value > 0 ? Math.max(3, (p.value / max) * (h - 8)) : 0;
          const x = i * slot + (slot - bar) / 2;
          return (
            <g key={p.date} onMouseEnter={() => setActive(i)} onFocus={() => setActive(i)} onBlur={() => setActive(null)} tabIndex={0}
              aria-label={`${day.format(new Date(p.date))}: ${format(p.value)}`}>
              <rect x={i * slot} y={0} width={slot} height={h} fill="transparent" />
              {bh > 0 && <rect x={x} y={h - bh} width={bar} height={bh} rx={Math.min(4, bar / 2)} className={active === i ? "fill-sun-deep" : "fill-sun"} />}
            </g>
          );
        })}
        {points.length > 0 && <>
          <text x={0} y={h + 16} className="fill-ink-muted text-[11px]">{day.format(new Date(points[0].date))}</text>
          <text x={w} y={h + 16} textAnchor="end" className="fill-ink-muted text-[11px]">{day.format(new Date(points[points.length - 1].date))}</text>
        </>}
      </svg>
      </div>
      <details className="mt-2 text-sm">
        <summary className="cursor-pointer text-ink-muted">{tableLabel}</summary>
        <table className="mt-2 w-full text-start">
          <thead><tr><th className="text-start font-semibold">{dayLabel}</th><th className="text-start font-semibold">{title}</th></tr></thead>
          <tbody>
            {points.filter((p) => p.value > 0).map((p) => (
              <tr key={p.date}><td className="num">{day.format(new Date(p.date))}</td><td className="num">{format(p.value)}</td></tr>
            ))}
          </tbody>
        </table>
      </details>
    </figure>
  );
}
