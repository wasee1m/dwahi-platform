import { Fragment, type ReactNode } from "react";

// عارض Markdown محدود لصفحات المحتوى (FR-CMS-01): عناوين، وفقرات، وقوائم، واقتباس، وخط عريض.
// يبني عناصر React مباشرة، فلا HTML خام من قاعدة البيانات يصل للصفحة (04 §10: تنقية المحتوى المُدار).
function inline(text: string): ReactNode[] {
  return text.split(/(\*\*[^*]+\*\*)/g).map((part, i) =>
    part.startsWith("**") && part.endsWith("**") ? <strong key={i}>{part.slice(2, -2)}</strong> : <Fragment key={i}>{part}</Fragment>,
  );
}

export function Markdown({ source }: { source: string }) {
  const blocks = source.replace(/\r\n/g, "\n").split(/\n{2,}/);
  return (
    <div className="space-y-4 leading-relaxed">
      {blocks.map((block, i) => {
        const lines = block.split("\n").filter(Boolean);
        if (lines.length === 0) return null;
        if (lines.every((l) => l.startsWith("- "))) {
          return (
            <ul key={i} className="list-disc space-y-1 ps-6">
              {lines.map((l, j) => <li key={j}>{inline(l.slice(2))}</li>)}
            </ul>
          );
        }
        if (lines[0].startsWith("### ")) return <h3 key={i} className="pt-2 text-lg">{inline(lines[0].slice(4))}</h3>;
        if (lines[0].startsWith("## ")) return <h2 key={i} className="pt-2 text-2xl">{inline(lines[0].slice(3))}</h2>;
        if (lines[0].startsWith("> ")) {
          return (
            <blockquote key={i} className="border-s-4 border-sun bg-sand-200 px-4 py-3 text-ink-muted">
              {inline(lines.map((l) => l.replace(/^> ?/, "")).join(" "))}
            </blockquote>
          );
        }
        return <p key={i}>{inline(lines.join(" "))}</p>;
      })}
    </div>
  );
}
