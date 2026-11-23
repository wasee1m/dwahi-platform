import Link from "next/link";
import { notFound } from "next/navigation";
import QRCode from "qrcode";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed } from "@/lib/auth/client";
import type { ServiceDetail } from "@/lib/service-types";
import { PrintButton } from "./PrintButton";

// ملصق QR لبطاقة الاستلام (FR-SRV-08): يُطبع ويُثبّت على المعدة أو المكوّن، ومسحه يفتح الطلب من A-22 وتطبيق الفني (FR-TEC-01)
type Props = PageProps<"/[lang]/admin/workshop-desk/label/[id]">;

export default function LabelPage({ params }: Props) {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <Label params={params} />
    </Suspense>
  );
}

async function Label({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const res = await authed<ServiceDetail>("admin", lang, `/admin/service-requests/${id}`);
  if (!res.ok || !res.data.intake) notFound();
  const r = res.data;
  const intake = r.intake!;
  const svg = await QRCode.toString(intake.number, { type: "svg", margin: 1, width: 220, color: { dark: "#000000", light: "#FFFFFF" } });
  const a = t.admin.service;
  const time = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap gap-3 print:hidden">
        <Link href={`/${lang}/admin/service-requests/${r.id}`} className="text-sm font-semibold text-ink-muted">← <span className="num" dir="ltr">{r.number}</span></Link>
        <PrintButton label={a.print} />
        {intake.receipt_url && <a href={intake.receipt_url} target="_blank" rel="noopener noreferrer" className="rounded-sm border border-ink px-4 py-2 text-sm font-semibold">{t.service.receipt}</a>}
      </div>
      <section className="mx-auto w-[90mm] border-2 border-black bg-white p-4 text-center text-black">
        <div className="mx-auto w-48" dangerouslySetInnerHTML={{ __html: svg }} />
        <p className="num mt-2 text-2xl font-extrabold tracking-wider" dir="ltr">{intake.number}</p>
        <p className="num text-sm" dir="ltr">{r.number}</p>
        <p className="mt-1 text-sm font-semibold">{r.customer?.organization ?? r.customer?.name}</p>
        <p className="text-sm">{r.equipment?.name ?? t.service.components[r.component_type as keyof typeof t.service.components]}</p>
        {intake.received_at && <p className="text-xs">{time.format(new Date(intake.received_at))}</p>}
      </section>
    </div>
  );
}
