import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { EquipmentForm, PhotoManager, StatusPanel, type Listing } from "../EquipmentForms";
import { editorOptions } from "../options";

// A-02 تعديل معروض: البيانات، والصور بترتيبها، والنشر والحجز والبيع (FR-CAT-06، US-36، BR-19)
type Props = PageProps<"/[lang]/admin/equipment/[id]">;

// المعرّف غير معروف وقت البناء، فكل ما يقرأ params داخل Suspense
export default function EditEquipmentPage({ params, searchParams }: Props) {
  return (
    <div className="max-w-5xl space-y-6">
      <Suspense fallback={<Skeleton className="h-[700px]" />}>
        <Editor params={params} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

async function Editor({ params, searchParams }: { params: Props["params"]; searchParams: Props["searchParams"] }) {
  const [{ lang, id }, sp] = await Promise.all([params, searchParams]);
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("catalog.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const [res, { models, branches }] = await Promise.all([authed<Listing>("admin", lang, `/admin/equipment/${encodeURIComponent(id)}`), editorOptions(lang)]);
  if (!res.ok) notFound();
  const listing = res.data;
  const e = t.admin.equipment;

  return (
    <>
      <Link href={`/${lang}/admin/equipment`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{lang === "ar" ? listing.title_ar : listing.title_en}</h1>
        {listing.status !== "draft" && listing.status !== "archived" && (
          <Link href={`/${lang}/equipment/${listing.slug}`} target="_blank" className="rounded-sm border border-ink px-4 py-2 font-semibold">{e.viewPublic} ↗</Link>
        )}
      </div>
      {sp.created && <Notice tone="success">{t.admin.common.saved}</Notice>}
      <StatusPanel key={listing.status} lang={lang} t={e} listing={listing} />
      <PhotoManager lang={lang} t={e} c={t.admin.common} listingId={listing.id} initial={listing.media} />
      <EquipmentForm lang={lang} t={e} c={t.admin.common} listing={listing} models={models} branches={branches} />
    </>
  );
}
