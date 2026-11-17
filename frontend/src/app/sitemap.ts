import type { MetadataRoute } from "next";
import { locales } from "@/i18n/config";
import { listEquipment } from "@/lib/api";
import { safe } from "@/lib/safe";
import { siteUrl } from "@/lib/seo";

// خريطة الموقع باللغتين (NFR-22): الصفحات الثابتة وكل معدة معروضة
const STATIC = ["", "/equipment", "/parts", "/services", "/about", "/branches", "/contact", "/faq", "/terms", "/privacy", "/returns", "/warranty"];

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entry = (path: string): MetadataRoute.Sitemap[number] => ({
    url: `${siteUrl}/ar${path}`,
    alternates: { languages: Object.fromEntries(locales.map((l) => [l, `${siteUrl}/${l}${path}`])) },
  });

  const slugs: string[] = [];
  let cursor: string | undefined;
  for (let i = 0; i < 50; i++) {
    const page = await safe(listEquipment("ar", { cursor }), { data: [], next_cursor: null });
    slugs.push(...page.data.map((e) => e.slug));
    if (!page.next_cursor) break;
    cursor = page.next_cursor;
  }

  return [...STATIC.map(entry), ...slugs.map((s) => entry(`/equipment/${s}`))];
}
