import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale } from "@/i18n/config";
import { getAppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { PageEditor, type PageDoc } from "../../ContentForms";

// A-14 تعديل صفحة، أو صفحة جديدة عند /new. المعرّف غير معروف وقت البناء، فكل ما يقرأ params داخل Suspense.
type Props = PageProps<"/[lang]/admin/content/pages/[id]">;

// الصفحات التي لها مسار ثابت في الموقع (W-07، W-11)
const PUBLIC_PATHS: Record<string, string> = { about: "/about", terms: "/terms", privacy: "/privacy", returns: "/returns", warranty: "/warranty" };

export default function EditContentPage({ params, searchParams }: Props) {
  return (
    <div className="max-w-6xl space-y-6">
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
  if (!me.permissions.includes("content.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  let page: PageDoc | undefined;
  if (id !== "new") {
    const r = await authed<PageDoc>("admin", lang, `/admin/pages/${encodeURIComponent(id)}`);
    if (!r.ok) notFound();
    page = r.data;
  }
  const s = t.admin.content;
  const publicPath = page && page.status === "published" ? PUBLIC_PATHS[page.slug] : undefined;

  return (
    <>
      <Link href={`/${lang}/admin/content?tab=pages`} className="text-sm font-semibold text-ink-muted">← {t.admin.common.back}</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-3xl">{page ? (lang === "ar" ? page.title_ar : page.title_en) : s.newPage}</h1>
        {publicPath && <Link href={`/${lang}${publicPath}`} target="_blank" className="rounded-sm border border-ink px-4 py-2 font-semibold">{s.viewPublic} ↗</Link>}
      </div>
      {sp.created && <Notice tone="success">{t.admin.common.saved}</Notice>}
      <PageEditor key={page?.id ?? "new"} lang={lang} t={s} c={t.admin.common} page={page} />
    </>
  );
}
