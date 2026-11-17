import Image from "next/image";
import Link from "next/link";
import type { Locale } from "@/i18n/config";
import type { Dictionary } from "@/i18n/get-dictionary";
import { formatMoney, formatNumber } from "@/lib/format";
import type { EquipmentCard as Card } from "@/lib/types";
import { ConditionBadge } from "./Badges";
import { LogoMark } from "./Logo";

// بطاقة المعدة (06: صورة 4:3 + شارة الحالة + الماركة والموديل + السنة والساعات + السعر + الفرع + التفاصيل)
export function EquipmentCard({ item, lang, dict }: { item: Card; lang: Locale; dict: Dictionary }) {
  const t = dict.equipment;
  const subtitle = item.kind === "component"
    ? item.component_model
    : [item.brand?.name, item.model_name].filter(Boolean).join(" ");

  return (
    <article className="group flex flex-col overflow-hidden rounded-md border border-sand-300 bg-sand-0 transition hover:border-ink-muted">
      <Link href={`/${lang}/equipment/${item.slug}`} className="flex flex-1 flex-col">
        <div className="relative aspect-[4/3] bg-sand-200">
          {item.cover_image_url ? (
            <Image src={item.cover_image_url} alt={item.title} fill sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw" className="object-cover" />
          ) : (
            <div className="flex h-full flex-col items-center justify-center gap-2 text-ink-muted opacity-60">
              <LogoMark size={56} />
              <span className="text-xs">{t.noImage}</span>
            </div>
          )}
          <div className="absolute start-3 top-3 flex gap-1.5">
            <ConditionBadge item={item} dict={dict} />
          </div>
        </div>
        <div className="flex flex-1 flex-col gap-1.5 p-4">
          <h3 className="font-sans text-base leading-snug font-bold">{item.title}</h3>
          {subtitle && <p className="text-sm text-ink-muted">{subtitle}</p>}
          {(item.year || item.hour_meter) && (
            <p className="flex gap-3 text-sm text-ink-muted">
              {item.year && <span>{t.year} <span className="num">{item.year}</span></span>}
              {item.hour_meter !== null && item.condition !== "new" && (
                <span><span className="num">{formatNumber(item.hour_meter)}</span> {t.hoursUnit}</span>
              )}
            </p>
          )}
          <div className="mt-auto flex items-end justify-between gap-2 pt-3">
            <p className="font-bold text-sun-text">
              {item.price ? (
                <>
                  <span className="num">{formatMoney(item.price, lang)}</span>
                  <span className="ms-1 text-xs font-normal text-ink-muted">{t.exclVat}</span>
                </>
              ) : t.priceOnRequest}
            </p>
            {item.branch && <p className="text-xs text-ink-muted">{item.branch.name}</p>}
          </div>
        </div>
      </Link>
    </article>
  );
}
