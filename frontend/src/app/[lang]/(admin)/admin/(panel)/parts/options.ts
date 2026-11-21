import "server-only";
import type { Locale } from "@/i18n/config";
import { authed } from "@/lib/auth/client";
import type { Brand, Model } from "../catalog-setup/SetupForms";

// قوائم الاختيار في شاشات القطع: الماركات والموديلات (catalog.read) والمخازن
export async function partOptions(lang: Locale) {
  const [brands, models, locations] = await Promise.all([
    authed<Brand[]>("admin", lang, "/admin/brands"),
    authed<Model[]>("admin", lang, "/admin/models"),
    stockLocations(lang),
  ]);
  return {
    brands: (brands.ok ? brands.data : []).map((b) => ({ value: b.id, label: b.name })),
    models: (models.ok ? models.data : []).map((m) => ({ value: m.id, label: `${m.brand} ${m.model_name}` })).sort((a, b) => a.label.localeCompare(b.label)),
    locations,
  };
}

export async function stockLocations(lang: Locale) {
  const r = await authed<{ id: string; name: string }[]>("admin", lang, "/admin/stock-locations");
  return (r.ok ? r.data : []).map((l) => ({ value: l.id, label: l.name }));
}
