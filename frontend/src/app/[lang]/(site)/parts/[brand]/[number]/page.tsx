import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { AvailabilityBadge } from "@/components/Badges";
import { LogoMark } from "@/components/Logo";
import { ButtonLink, Container, Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getDictionary } from "@/i18n/get-dictionary";
import { getPart } from "@/lib/api";
import { portalEnabled } from "@/lib/features";
import { formatMoney } from "@/lib/format";
import { alternates } from "@/lib/seo";
import { AddToCart } from "../../../cart/CartForms";

// W-05 تفاصيل القطعة (FR-PRT-03، FR-CAT-07). "أضف للسلة" للقطعة المتوفرة (FR-ORD-01)، و"اطلب كمية" لغيرها.
type Props = PageProps<"/[lang]/parts/[brand]/[number]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { lang, brand, number } = await params;
  if (!hasLocale(lang)) return {};
  const part = await getPart(lang, brand, number);
  if (!part) return {};
  return { title: `${part.part_number} ${part.name}`, alternates: alternates(lang, `/parts/${brand}/${number}`) };
}

// المسار ديناميكي، فقراءة params داخل Suspense
export default function PartPage({ params }: Props) {
  return (
    <Suspense fallback={<Container className="py-10"><Skeleton className="h-96" /></Container>}>
      <Detail params={params} />
    </Suspense>
  );
}

async function Detail({ params }: { params: Props["params"] }) {
  const { lang, brand, number } = await params;
  if (!hasLocale(lang)) notFound();
  const dict = await getDictionary(lang);
  const part = await getPart(lang, brand, number);
  if (!part) notFound();
  const t = dict.parts;

  return (
    <Container className="py-8">
      <nav aria-label="breadcrumb" className="mb-6 text-sm text-ink-muted">
        <Link href={`/${lang}/parts`} className="hover:underline">{t.title}</Link>
      </nav>
      <div className="grid gap-10 md:grid-cols-[1fr_1.2fr]">
        <div className="relative aspect-square overflow-hidden rounded-md bg-sand-200">
          {part.media[0] ? (
            <Image src={part.media[0].url} alt={part.name} fill sizes="(min-width: 768px) 40vw, 100vw" className="object-contain" />
          ) : (
            <div className="flex h-full items-center justify-center opacity-50"><LogoMark size={96} /></div>
          )}
        </div>
        <div>
          <p className="text-sm text-ink-muted">{part.brand.name}</p>
          <h1 className="mt-1 text-2xl md:text-3xl"><span className="num">{part.part_number}</span></h1>
          <p className="mt-2 text-lg">{part.name}</p>
          <div className="mt-4"><AvailabilityBadge level={part.availability} dict={dict} /></div>
          <p className="mt-5 text-2xl font-bold text-sun-text"><span className="num">{formatMoney(part.price, lang)}</span></p>
          <p className="text-sm text-ink-muted">{t.vatLine}</p>

          <ul className="mt-5 space-y-1 text-sm">
            {part.warranty_months && <li>{dict.equipment.warranty}: <span className="num">{part.warranty_months}</span> {dict.equipment.warrantyMonths}</li>}
            <li>{part.is_returnable ? t.returnable : t.notReturnable}</li>
          </ul>

          {portalEnabled() && part.availability !== "on_request" && (
            <div className="mt-6">
              <AddToCart lang={lang} part={part.id} back={`/${lang}/parts/${brand}/${number}`}
                labels={{ add: t.addToCart, added: t.added, viewCart: t.viewCart, quantity: t.quantity }} />
              <p className="mt-2 max-w-md text-xs text-ink-muted">{t.payOnPickup}</p>
            </div>
          )}
          <div className="mt-4 flex flex-wrap gap-3">
            <ButtonLink variant={portalEnabled() && part.availability !== "on_request" ? "secondary" : "primary"} href={portalEnabled()
              ? `/${lang}/account/quotes/new?type=parts&part=${part.id}&label=${encodeURIComponent(`${part.brand.name} ${part.part_number} · ${part.name}`)}`
              : `/${lang}/contact?topic=parts&ref=${encodeURIComponent(part.part_number)}`}>{t.requestQuantity}</ButtonLink>
          </div>

          {part.description && <p className="mt-6 leading-relaxed">{part.description}</p>}

          {part.alternatives.length > 0 && (
            <section className="mt-6">
              <h2 className="text-base">{t.altNumbers}</h2>
              <ul className="mt-2 flex flex-wrap gap-2">
                {part.alternatives.map((a) => (
                  <li key={a.number} className="rounded-sm bg-sand-200 px-3 py-1 text-sm">
                    <span className="num">{a.number}</span>{a.brand && <span className="text-ink-muted"> · {a.brand}</span>}
                  </li>
                ))}
              </ul>
            </section>
          )}
          {part.compatible_models.length > 0 && (
            <section className="mt-6">
              <h2 className="text-base">{t.compatible}</h2>
              <ul className="mt-2 flex flex-wrap gap-2">
                {part.compatible_models.map((m) => (
                  <li key={m.slug} className="rounded-sm border border-sand-300 px-3 py-1 text-sm">{m.brand} {m.model_name}</li>
                ))}
              </ul>
            </section>
          )}
        </div>
      </div>
    </Container>
  );
}
