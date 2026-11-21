import { accessToken } from "@/lib/auth/session";

// تنزيل قالب الاستيراد باسم الموظف: الرمز في كوكي HttpOnly، فيمر الطلب عبر خادم Next.js
const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export async function GET() {
  const token = await accessToken("admin");
  if (!token) return new Response(null, { status: 401 });
  const res = await fetch(`${API_URL}/admin/parts/import/template`, { headers: { Authorization: `Bearer ${token}`, Accept: "text/csv" }, cache: "no-store" });
  if (!res.ok) return new Response(null, { status: res.status });
  return new Response(res.body, {
    headers: { "Content-Type": "text/csv; charset=UTF-8", "Content-Disposition": 'attachment; filename="parts-import-template.csv"' },
  });
}
