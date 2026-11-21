import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense, type ReactNode } from "react";
import { Notice } from "@/components/forms";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { BrandForm, CategoryForm, ModelForm, type Brand, type Category, type Model } from "./SetupForms";

// A-03 الماركات والفئات والموديلات (FR-CAT-06): تبويب في الرابط (?tab=)، وكل صف يُفتح للتعديل في مكانه
type Props = PageProps<"/[lang]/admin/catalog-setup">;
const TABS = ["brands", "categories", "models"] as const;
type Tab = (typeof TABS)[number];

export default async function CatalogSetupPage({ params, searchParams }: Props) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="space-y-6">
      <h1 className="text-3xl">{t.admin.catalogSetup.title}</h1>
      <Suspense fallback={<Skeleton className="h-96" />}>
        <Setup lang={lang} t={t} searchParams={searchParams} />
      </Suspense>
    </div>
  );
}

function Row({ title, sub, children }: { title: ReactNode; sub?: ReactNode; children: ReactNode }) {
  return (
    <details className="group rounded-md border border-sand-300 bg-sand-0">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-sand-100">
        <span><span className="font-semibold">{title}</span>{sub && <span className="ms-2 text-sm text-ink-muted">{sub}</span>}</span>
        <span aria-hidden className="text-ink-muted transition group-open:rotate-180">▾</span>
      </summary>
      <div className="border-t border-sand-300 p-4">{children}</div>
    </details>
  );
}

async function Setup({ lang, t, searchParams }: { lang: Locale; t: AppDictionary; searchParams: Props["searchParams"] }) {
  const me = await currentUser("admin", lang);
  if (!me.permissions.includes("catalog.manage")) return <Notice tone="danger">{t.admin.forbidden}</Notice>;

  const sp = await searchParams;
  const tab: Tab = TABS.includes(sp.tab as Tab) ? (sp.tab as Tab) : "brands";
  const [b, cat, m] = await Promise.all([
    authed<Brand[]>("admin", lang, "/admin/brands"),
    authed<Category[]>("admin", lang, "/admin/categories"),
    tab === "models" ? authed<Model[]>("admin", lang, "/admin/models") : Promise.resolve(null),
  ]);
  const brands = b.ok ? b.data : [];
  const categories = cat.ok ? cat.data : [];
  const models = m?.ok ? m.data : [];
  const s = t.admin.catalogSetup;
  const c = t.admin.common;
  const catName = (id: string | null) => categories.find((x) => x.id === id)?.[lang === "ar" ? "name_ar" : "name_en"];

  return (
    <>
      <nav className="flex gap-1 border-b border-sand-300" aria-label={s.title}>
        {TABS.map((x) => (
          <Link key={x} href={`?tab=${x}`} aria-current={x === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-4 py-2 font-semibold ${x === tab ? "border-sun text-ink" : "border-transparent text-ink-muted hover:text-ink"}`}>
            {s[x]}
          </Link>
        ))}
      </nav>

      <section className="space-y-3">
        <Row title={`+ ${c.new}`}>
          {tab === "brands" && <BrandForm key="new" lang={lang} t={s} c={c} />}
          {tab === "categories" && <CategoryForm key="new" lang={lang} t={s} c={c} categories={categories} />}
          {tab === "models" && <ModelForm key="new" lang={lang} t={s} c={c} brands={brands} categories={categories} />}
        </Row>

        {tab === "brands" && brands.map((x) => (
          <Row key={x.id} title={<span dir="ltr">{x.name}</span>} sub={[x.is_represented && s.represented, !x.is_active && `(${c.no} ${s.active})`].filter(Boolean).join(" · ")}>
            <BrandForm lang={lang} t={s} c={c} brand={x} />
          </Row>
        ))}
        {tab === "categories" && categories.map((x) => (
          <Row key={x.id} title={lang === "ar" ? x.name_ar : x.name_en} sub={x.parent_id ? `← ${catName(x.parent_id)}` : undefined}>
            <CategoryForm lang={lang} t={s} c={c} category={x} categories={categories} />
          </Row>
        ))}
        {tab === "models" && models.map((x) => (
          <Row key={x.id} title={<span dir="ltr">{x.brand} {x.model_name}</span>} sub={catName(x.category_id)}>
            <ModelForm lang={lang} t={s} c={c} model={x} brands={brands} categories={categories} />
          </Row>
        ))}
        {tab === "models" && models.length === 0 && <p className="p-6 text-center text-ink-muted">{c.empty}</p>}
      </section>
    </>
  );
}
