import { accessToken } from "@/lib/auth/session";

// معاينة PDF العرض في لوحة الإدارة: المسودة تُولَّد بعلامة DRAFT، والمرسل نسخته المحفوظة
const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[0-9a-f-]{36}$/i.test(id)) return new Response(null, { status: 404 });
  const token = await accessToken("admin");
  if (!token) return new Response(null, { status: 401 });
  const res = await fetch(`${API_URL}/admin/quotations/${id}/pdf`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/pdf" }, cache: "no-store" });
  if (!res.ok) return new Response(null, { status: res.status });
  return new Response(res.body, { headers: { "Content-Type": "application/pdf", "Content-Disposition": "inline", "Cache-Control": "private, no-store" } });
}
