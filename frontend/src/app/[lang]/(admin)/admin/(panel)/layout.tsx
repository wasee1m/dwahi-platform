import Link from "next/link";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Logo } from "@/components/Logo";
import { Skeleton } from "@/components/ui";
import { hasLocale, type Locale } from "@/i18n/config";
import { getAppDictionary, type AppDictionary } from "@/i18n/get-dictionary";
import { authed, currentUser } from "@/lib/auth/client";
import { staffLogout } from "../actions";

// إطار لوحة الإدارة (06 §3): قائمة جانبية بالأقسام المسموحة لدور المستخدم فقط (01 §3.2، US-39)
export default async function PanelLayout({ children, params }: LayoutProps<"/[lang]/admin">) {
  const { lang } = await params;
  if (!hasLocale(lang)) notFound();
  const t = await getAppDictionary(lang);
  return (
    <div className="grid min-h-dvh lg:grid-cols-[250px_1fr]">
      <Suspense fallback={<aside className="bg-night p-5"><Skeleton className="h-64 opacity-20" /></aside>}>
        <Sidebar lang={lang} t={t} />
      </Suspense>
      <main className="min-w-0 p-6 lg:p-10">{children}</main>
    </div>
  );
}

async function Sidebar({ lang, t }: { lang: Locale; t: AppDictionary }) {
  const me = await currentUser("admin", lang);
  const count = await authed<{ unread_count: number }>("admin", lang, "/admin/notifications/count");
  const unread = count.ok ? count.data.unread_count : 0;
  const items: { href: string; label: string; ability: string | null; badge?: number }[] = [
    { href: "/admin", label: t.admin.nav.home, ability: null },
    { href: "/admin/rfqs", label: t.admin.nav.rfqs, ability: "quotation.send" },
    { href: "/admin/notifications", label: t.admin.nav.notifications, ability: null, badge: unread },
    { href: "/admin/equipment", label: t.admin.nav.equipment, ability: "catalog.manage" },
    { href: "/admin/catalog-setup", label: t.admin.nav.catalogSetup, ability: "catalog.manage" },
    { href: "/admin/parts", label: t.admin.nav.parts, ability: "parts.manage" },
    { href: "/admin/leads", label: t.admin.nav.leads, ability: "leads.manage" },
    { href: "/admin/content", label: t.admin.nav.content, ability: "content.manage" },
    { href: "/admin/settings", label: t.admin.nav.settings, ability: "settings.manage" },
    { href: "/admin/users", label: t.admin.nav.users, ability: "staff.manage" },
    { href: "/admin/audit-log", label: t.admin.nav.audit, ability: "audit.view" },
  ].filter((i) => !i.ability || me.permissions.includes(i.ability));

  return (
    <aside className="flex flex-col bg-night p-5 text-on-night">
      <Link href={`/${lang}/admin`} className="block"><Logo inverse /></Link>
      <nav aria-label={t.admin.panel} className="mt-8 flex-1">
        <ul className="space-y-1">
          {items.map((i) => (
            <li key={i.href}>
              <Link href={`/${lang}${i.href}`} className="flex items-center justify-between rounded-sm px-3 py-2 font-semibold hover:bg-white/10">
                {i.label}
                {i.badge ? <span className="num rounded-[999px] bg-sun px-2 text-xs font-bold text-on-sun">{i.badge}</span> : null}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
      <div className="mt-6 border-t border-white/15 pt-4 text-sm">
        <p className="font-semibold">{me.full_name}</p>
        <p className="mt-0.5 opacity-75">{me.roles.map((r) => t.admin.roles[r as keyof AppDictionary["admin"]["roles"]] ?? r).join("، ")}</p>
        <div className="mt-3 flex items-center justify-between gap-2">
          <Link href={`/${lang === "ar" ? "en" : "ar"}/admin`} className="rounded-sm border border-white/30 px-3 py-1">{lang === "ar" ? "English" : "العربية"}</Link>
          <form action={staffLogout}>
            <input type="hidden" name="lang" value={lang} />
            <button className="rounded-sm px-3 py-1 font-semibold hover:bg-white/10">{t.admin.nav.logout}</button>
          </form>
        </div>
      </div>
    </aside>
  );
}
