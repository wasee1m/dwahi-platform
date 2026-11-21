import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { ConditionBadge } from "@/components/Badges";
import { EquipmentCard } from "@/components/EquipmentCard";
import { LogoMark } from "@/components/Logo";
import { ShareButton } from "@/components/ShareButton";
import { ButtonLink, Container, Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { getEquipment } from "@/lib/api";
import { portalEnabled } from "@/lib/features";
import { formatMoney, formatNumber, whatsappLink } from "@/lib/format";
import { alternates, siteUrl } from "@/lib/seo";

// W-03 تفاصيل المعدة (FR-CAT-03، FR-CAT-07، US-02). "اطلب عرض سعر" يفتح نموذج التواصل حتى تكتمل العروض (S4).
type Props = PageProps<"/[lang]/equipment/[slug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { lang, slug } = await params;
  if (!hasLocale(lang)) return {};
  const item = await getEquipment(lang, slug);
  if (!item) return {};
  return {
    title: item.title,
    description: item.description ?? undefined,
    alternates: alternates(lang, `/equipment/${slug}`),
    openGraph: item.media[0] ? { images: [item.media[0].url] } : undefined,
  };
}

// المسار ديناميكي (slug غير معروف وقت البناء)، فكل قراءة لـ params داخل Suspense ليبقى إطار الصفحة ثابتاً
export default function EquipmentDetailPage({ params }: Props) {
  return (
    <Suspense fallback={<Container className="py-10"><Skeleton className="h-[480px]" /></Container>}>
      <Detail params={params} />
    </Suspense>
  );
}

async function Detail({ params }: { params: Props["params"] }) {
  const { lang, slug } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  const item = await getEquipment(lang, slug);
  if (!item) notFound();
  const t = dict.equipment;
  const url = `${siteUrl}/${lang}/equipment/${item.slug}`;
  const wa = item.branch?.whatsapp_sales;

  const facts: [string, React.ReactNode][] = [];
  if (item.brand) facts.push([t.brand, item.brand.name]);
  if (item.model_name) facts.push([t.model, item.model_name]);
  if (item.component_model) facts.push([t.componentModel, item.component_model]);
  if (item.source_machine) facts.push([t.sourceMachine, item.source_machine]);
  if (item.year) facts.push([t.year, <span key="y" className="num">{item.year}</span>]);
  if (item.hour_meter !== null && item.condition !== "new") facts.push([t.hours, <span key="h"><span className="num">{formatNumber(item.hour_meter)}</span> {t.hoursUnit}</span>]);
  if (item.missing_parts) facts.push([t.missingParts, item.missing_parts]);
  facts.push([t.warranty, item.warranty_months ? <span key="w"><span className="num">{item.warranty_months}</span> {t.warrantyMonths}</span> : t.noWarranty]);
  if (item.branch) facts.push([t.branch, item.branch.name]);

  // بيانات منظمة Product (NFR-22)
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "Product",
    name: item.title,
    brand: item.brand ? { "@type": "Brand", name: item.brand.name } : undefined,
    itemCondition: item.condition === "new" ? "https://schema.org/NewCondition"
      : item.condition === "rebuilt" ? "https://schema.org/RefurbishedCondition" : "https://schema.org/UsedCondition",
    image: item.media.map((m) => m.url),
    url,
    offers: item.price ? {
      "@type": "Offer", price: item.price.amount, priceCurrency: item.price.currency,
      availability: item.status === "published" ? "https://schema.org/InStock" : "https://schema.org/SoldOut",
    } : undefined,
  };

  return (
    <Container className="py-8">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd).replace(/</g, "\\u003c") }} />
      <nav aria-label="breadcrumb" className="mb-6 text-sm text-ink-muted">
        <Link href={`/${lang}/equipment`} className="hover:underline">{t.title}</Link>
        {item.category && <> / <Link href={`/${lang}/equipment/c/${item.category.slug}`} className="hover:underline">{item.category.name}</Link></>}
      </nav>

      <div className="grid gap-10 lg:grid-cols-[1.3fr_1fr]">
        <div>
          <div className="relative aspect-[4/3] overflow-hidden rounded-md bg-sand-200">
            {item.media[0] ? (
              <Image src={item.media[0].url} alt={item.title} fill priority sizes="(min-width: 1024px) 60vw, 100vw" className="object-cover" />
            ) : (
              <div className="flex h-full flex-col items-center justify-center gap-3 text-ink-muted opacity-60">
                <LogoMark size={96} />
                <span>{t.noImage}</span>
              </div>
            )}
          </div>
          {item.media.length > 1 && (
            <ul className="mt-3 grid grid-cols-5 gap-2">
              {item.media.slice(1, 6).map((m) => (
                <li key={m.url} className="relative aspect-square overflow-hidden rounded-sm bg-sand-200">
                  <Image src={m.url} alt="" fill sizes="20vw" className="object-cover" />
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <ConditionBadge item={item} dict={dict} />
          <h1 className="mt-3 text-2xl leading-snug md:text-3xl">{item.title}</h1>
          <p className="mt-4 text-2xl font-bold text-sun-text">
            {item.price ? (
              <><span className="num">{formatMoney(item.price, lang)}</span> <span className="text-sm font-normal text-ink-muted">{t.exclVat}</span></>
            ) : t.priceOnRequest}
          </p>

          <dl className="mt-6 divide-y divide-sand-300 rounded-md border border-sand-300 bg-sand-0">
            {facts.map(([k, v]) => (
              <div key={k} className="flex justify-between gap-4 px-4 py-3 text-sm">
                <dt className="text-ink-muted">{k}</dt>
                <dd className="font-semibold">{v}</dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-sm text-ink-muted">{t.exportAvailable}</p>

          {item.status !== "sold" && (
            <div className="mt-6 flex flex-wrap gap-3">
              <ButtonLink href={portalEnabled() && item.status === "published"
                ? `/${lang}/account/quotes/new?type=equipment&listing=${encodeURIComponent(item.slug)}`
                : `/${lang}/contact?topic=equipment&ref=${encodeURIComponent(item.title)}`}>{t.requestQuote}</ButtonLink>
              {wa && (
                <a href={whatsappLink(wa, `${t.whatsappMessage} ${item.title}\n${url}`)} rel="noopener" target="_blank"
                  className="inline-flex items-center justify-center rounded-sm bg-night px-5 py-3 font-bold text-on-night hover:opacity-90">
                  {t.askWhatsapp}
                </a>
              )}
              <ShareButton label={t.share} copiedLabel={t.copied} title={item.title} />
            </div>
          )}
        </div>
      </div>

      {(item.description || item.specs.length > 0) && (
        <div className="mt-12 grid gap-10 lg:grid-cols-2">
          {item.description && (
            <section>
              <h2 className="text-xl">{t.description}</h2>
              <p className="mt-3 leading-relaxed whitespace-pre-line">{item.description}</p>
            </section>
          )}
          {item.specs.length > 0 && (
            <section>
              <h2 className="text-xl">{t.specs}</h2>
              <table className="mt-3 w-full text-sm">
                <tbody className="divide-y divide-sand-300">
                  {item.specs.map((s) => (
                    <tr key={s.label}>
                      <th scope="row" className="py-2 text-start font-normal text-ink-muted">{s.label}</th>
                      <td className="py-2 text-end font-semibold"><span className="num">{s.value}</span> {s.unit}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}
        </div>
      )}

      {item.similar.length > 0 && (
        <section className="mt-14">
          <h2 className="text-xl">{t.similar}</h2>
          <div className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {item.similar.map((s) => <EquipmentCard key={s.id} item={s} lang={lang} dict={dict} />)}
          </div>
        </section>
      )}
    </Container>
  );
}
