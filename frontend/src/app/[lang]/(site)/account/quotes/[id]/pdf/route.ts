import { accessToken } from "@/lib/auth/session";

// تنزيل PDF العرض باسم العميل: الرمز في كوكي HttpOnly، فيمر الطلب عبر خادم Next.js، والـ API يتحقق من الرؤية
const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export async function GET(request: Request) {
  const q = new URL(request.url).searchParams.get("q") ?? "";
  if (!/^[0-9a-f-]{36}$/i.test(q)) return new Response(null, { status: 404 });
  const token = await accessToken("customer");
  if (!token) return new Response(null, { status: 401 });
  const res = await fetch(`${API_URL}/me/quotations/${q}/pdf`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/pdf" }, cache: "no-store" });
  if (!res.ok) return new Response(null, { status: res.status });
  return new Response(res.body, {
    headers: { "Content-Type": "application/pdf", "Content-Disposition": res.headers.get("content-disposition") ?? "attachment", "Cache-Control": "private, no-store" },
  });
}
