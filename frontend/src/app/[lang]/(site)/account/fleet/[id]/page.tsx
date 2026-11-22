import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ActionButton } from "@/components/ActionButton";
import { Notice } from "@/components/forms";
import { ButtonLink, Skeleton } from "@/components/ui";
import { hasLocale, intlLocale } from "@/i18n/config";
import { fill, getAppDictionary } from "@/i18n/get-dictionary";
import type { Address, CatalogModel, FleetEvent, FleetItem } from "@/lib/account-types";
import { authed } from "@/lib/auth/client";
import { formatNumber } from "@/lib/format";
import { archiveEquipment } from "../../portal-actions";
import type { RfqRow } from "../../quotes/shared";
import { EquipmentForm, ReadingForm } from "../FleetForms";

// C-06 تفاصيل معدة (FR-ACC-05، FR-PRT-02، US-04، US-10). طلبات الصيانة وتقاريرها تنضم للسجل في S7 و S9.
export const metadata = { robots: { index: false } };
type Props = PageProps<"/[lang]/account/fleet/[id]">;
const card = "rounded-md border border-sand-300 bg-sand-0 p-5";

export default function EquipmentPage({ params }: Props) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<Skeleton className="h-[600px]" />}>
        <Detail params={params} />
      </Suspense>
    </div>
  );
}

async function Detail({ params }: { params: Props["params"] }) {
  const { lang, id } = await params;
  if (!hasLocale(lang) || !/^[0-9a-f-]{36}$/i.test(id)) notFound();
  const t = await getAppDictionary(lang);
  const res = await authed<FleetItem>("customer", lang, `/me/fleet/${id}`);
  if (!res.ok) notFound();
  const e = res.data;
  const [history, models, sites] = await Promise.all([
    authed<FleetEvent[]>("customer", lang, `/me/fleet/${id}/history`),
    authed<CatalogModel[]>("customer", lang, "/public/models"),
    authed<Address[]>("customer", lang, `/me/addresses?kind=site&account=${e.account?.id ?? "personal"}`),
  ]);
  const f = t.fleet;
  const when = new Intl.DateTimeFormat(intlLocale(lang), { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Muscat" });

  return (
    <>
      <Link href={`/${lang}/account/fleet`} className="text-sm font-semibold text-ink-muted">← {f.title}</Link>
      <div>
        <h1 className="text-3xl">{e.nickname ?? e.machine}</h1>
        <p className="mt-1 text-ink-muted">
          {e.machine}
          {e.year && <> · <span className="num">{e.year}</span></>}
          {e.serial_number && <> · <span className="num" dir="ltr">{e.serial_number}</span></>}
          {e.site && <> · {e.site.label}</>}
          {e.account && <> · {e.account.name}</>}
        </p>
        {e.nameplate?.url && <a href={e.nameplate.url} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold underline">{f.nameplate}</a>}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {e.model_id ? (
          <ButtonLink href={`/${lang}/parts?model=${e.model_id}&equipment=${e.id}`}>{f.partsFor}</ButtonLink>
        ) : (
          <ButtonLink href={`/${lang}/account/quotes/new?type=parts&equipment=${e.id}`}>{f.requestPart}</ButtonLink>
        )}
        <span className="rounded-sm border border-sand-300 px-5 py-3 font-semibold text-ink-muted">{f.requestService} · {t.account.comingSoon}</span>
      </div>
      {!e.model_id && <Notice>{f.notLinked}</Notice>}

      <section className={card}>
        <h2 className="text-xl">{f.meter}</h2>
        <p className="my-2 text-2xl font-bold">{e.hour_meter === null ? f.noReading : fill(f.hours, { n: formatNumber(e.hour_meter) })}</p>
        <ReadingForm lang={lang} t={f} id={e.id} />
      </section>

      <section className={card}>
        <h2 className="text-xl">{f.history}</h2>
        {!history.ok ? <p className="mt-2 text-danger">{t.auth.genericError}</p> : history.data.length === 0 ? <p className="mt-2 text-ink-muted">{f.noHistory}</p> : (
          <ol className="mt-3 space-y-3 border-s-2 border-sand-300 ps-4">
            {history.data.map((h) => (
              <li key={h.id} className="text-sm">
                <p className="text-xs text-ink-muted">{when.format(new Date(h.at))}{h.by && ` · ${h.by}`}</p>
                {h.type === "reading" ? (
                  <p className="font-semibold">
                    {fill(f.hours, { n: formatNumber(h.reading ?? 0) })}
                    {h.needs_review && <span className="ms-2 text-danger">{f.needsReview}</span>}
                    {h.photo_url && <a href={h.photo_url} target="_blank" rel="noopener noreferrer" className="ms-2 underline">{f.photo}</a>}
                  </p>
                ) : (
                  <Link href={`/${lang}/account/quotes/${h.id}`} className="font-semibold underline">
                    <span className="num" dir="ltr">{h.number}</span> · {t.quotes.rfqStatuses[h.status as RfqRow["status"]]}
                  </Link>
                )}
                {h.note && <p className="text-ink-muted">{h.note}</p>}
              </li>
            ))}
          </ol>
        )}
      </section>

      <details className={card}>
        <summary className="cursor-pointer font-bold">{f.edit}</summary>
        <div className="mt-4">
          {models.ok && sites.ok && <EquipmentForm lang={lang} t={f} models={models.data} sites={sites.data} organizationId={e.account?.id ?? null} item={e} />}
        </div>
      </details>
      <ActionButton action={archiveEquipment} fields={{ lang, id: e.id }} label={f.archive} look="danger"
        confirm={{ question: f.archiveQuestion, yes: t.company.yes, no: t.company.no }} />
    </>
  );
}
