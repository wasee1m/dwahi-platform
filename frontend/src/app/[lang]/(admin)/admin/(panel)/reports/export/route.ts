import { hasLocale } from "@/i18n/config";
import { accessToken } from "@/lib/auth/session";

// FR-RPT-03: تنزيل التقرير بالفلاتر الحالية. الـ API يسجل العملية في التدقيق.
const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";
const KEYS = ["quotes", "service", "parts", "stockouts", "revenue"];

export async function GET(request: Request, { params }: { params: Promise<{ lang: string }> }) {
  const { lang } = await params;
  const url = new URL(request.url);
  const key = url.searchParams.get("report") ?? "";
  const format = url.searchParams.get("format") === "csv" ? "csv" : "xlsx";
  if (!KEYS.includes(key) || !hasLocale(lang)) return new Response(null, { status: 404 });
  const token = await accessToken("admin");
  if (!token) return new Response(null, { status: 401 });
  const q = new URLSearchParams({ format });
  for (const k of ["from", "to", "branch_id"]) {
    const v = url.searchParams.get(k);
    if (v) q.set(k, v);
  }
  const res = await fetch(`${API_URL}/admin/reports/${key}/export?${q}`, {
    headers: { Authorization: `Bearer ${token}`, "Accept-Language": lang }, cache: "no-store",
  });
  if (!res.ok) return new Response(null, { status: res.status });
  return new Response(res.body, {
    headers: {
      "Content-Type": res.headers.get("content-type") ?? "application/octet-stream",
      "Content-Disposition": res.headers.get("content-disposition") ?? `attachment; filename="${key}.${format}"`,
      "Cache-Control": "private, no-store",
    },
  });
}
