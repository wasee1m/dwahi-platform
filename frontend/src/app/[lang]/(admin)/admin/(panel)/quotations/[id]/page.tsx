import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { formatMoney } from "@/lib/format";
import type { Line } from "../../rfq-actions";
import { QuoteEditor } from "./QuoteEditor";

// A-06 محرر عرض السعر (FR-RFQ-02، US-32): المسودة تُعدّل، والمرسلة للعرض فقط (SM-2)
type Props = PageProps<"/[lang]/admin/quotations/[id]">;
type Q = {
  id: string; number: string | null; version: number; status: string; valid_until: string | null;
  estimated_duration: string | null; delivery_terms: string | null; warranty_terms: string | null; payment_terms: string | null;
  total: { amount: string; currency: string }; items: (Line & { line_total: string })[];
  rfq: { id: string; number: string; type: string; status: string; delivery_country: string }; default_vat_rate: string;
};

export default function QuotationPage({ params }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[640px]" />}>
        <Editor params={params} />
      </Suspense>
    </div>
  );
}

async function Editor({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("quotation.send")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const res = await authed<Q>("admin", lang, `/admin/quotations/${id}`);
  if (!res.ok) notFound();
  const q = res.data;
  const s = t.admin.quote;
  const vat = q.default_vat_rate;

  return (
    <>
      <Link href={`/${lang}/admin/rfqs/${q.rfq.id}`} className="text-sm font-semibold text-ink-muted">← {s.back} <span className="num" dir="ltr">{q.rfq.number}</span></Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{s.title} · <span className="num" dir="ltr">{q.number ?? `v${q.version}`}</span></h1>
        <a href={`/${lang}/admin/quotations/${q.id}/pdf`} target="_blank" className="rounded-sm border border-ink px-4 py-2 font-semibold">PDF ↗</a>
      </div>
      {q.rfq.delivery_country !== "OM" && <Notice>{t.admin.rfqs.export} <span className="num">{q.rfq.delivery_country}</span> · BR-01</Notice>}

      {q.status === "draft" ? (
        <QuoteEditor lang={lang} t={s} quotationId={q.id} rfqId={q.rfq.id} defaultVat={vat}
          initialLines={q.items}
          initialTerms={{ valid_until: q.valid_until, estimated_duration: q.estimated_duration, delivery_terms: q.delivery_terms, warranty_terms: q.warranty_terms, payment_terms: q.payment_terms }} />
      ) : (
        <>
          <Notice>{s.locked}</Notice>
          <div className="overflow-x-auto rounded-md border border-sand-300 bg-sand-0">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="bg-sand-200"><tr>{[s.descAr, s.qty, s.price, s.discount, s.vat, s.lineTotal].map((h) => <th key={h} scope="col" className="px-3 py-2 text-start">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-sand-300">
                {q.items.map((l, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">{l.description_ar}<span className="block text-xs text-ink-muted" dir="ltr">{l.description_en}</span></td>
                    <td className="num px-3 py-2">{Number(l.quantity)} {l.unit}</td>
                    <td className="num px-3 py-2">{l.unit_price}</td>
                    <td className="num px-3 py-2">{Number(l.discount_amount) ? l.discount_amount : "—"}</td>
                    <td className="num px-3 py-2">{Number(l.vat_rate)}%</td>
                    <td className="num px-3 py-2 font-semibold">{l.line_total}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="num text-end text-xl font-bold">{formatMoney(q.total, lang)}</p>
        </>
      )}
    </>
  );
}
