import { NextResponse, type NextRequest } from "next/server";
import { defaultLocale, hasLocale, type Locale } from "@/i18n/config";

// 1) كل صفحة تحت /ar أو /en (اللغة من الكوكي، ثم المتصفح، ثم العربية).
// 2) صفحات الحساب والإدارة تتطلب جلسة: إن انتهت كوكي الوصول وبقيت كوكي التحديث، يُجدَّد الرمز هنا
//    قبل العرض (الكتابة على الكوكيز غير مسموحة أثناء عرض الصفحة نفسها).

const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";
const COOKIES = { customer: { at: "dw_at", rt: "dw_rt" }, admin: { at: "dwa_at", rt: "dwa_rt" } } as const;
type Area = keyof typeof COOKIES;
type Tokens = { access_token: string; access_expires_at: string; refresh_token: string; refresh_expires_at: string };

function preferredLocale(request: NextRequest): Locale {
  const saved = request.cookies.get("NEXT_LOCALE")?.value;
  if (saved && hasLocale(saved)) return saved;
  const first = (request.headers.get("accept-language") ?? "").split(",")[0]?.trim().slice(0, 2).toLowerCase();
  return first === "en" ? "en" : defaultLocale;
}

function protectedArea(rest: string): Area | null {
  if (/^\/admin(\/|$)/.test(rest)) {
    return /^\/admin\/(login|set-password|forgot)(\/|$)/.test(rest) ? null : "admin";
  }
  return /^\/(account|onboarding|cart|checkout)(\/|$)/.test(rest) ? "customer" : null;
}

// طلبات متزامنة (مثل جلب الروابط مسبقاً) تحمل نفس رمز التحديث: نجدده مرة واحدة ونشارك النتيجة،
// وإلا اعتبر الـ API الطلب الثاني إعادة استخدام وأنهى كل الجلسات. (نسخة واحدة من الخادم؛ مع عدة نسخ يلزم Redis.)
const inflight = new Map<string, { at: number; promise: Promise<Tokens | null> }>();

function refreshOnce(refreshToken: string, locale: Locale, client: Record<string, string>): Promise<Tokens | null> {
  const now = Date.now();
  for (const [k, v] of inflight) if (now - v.at > 30_000) inflight.delete(k);
  const cached = inflight.get(refreshToken);
  if (cached) return cached.promise;

  const promise = fetch(`${API_URL}/auth/token/refresh`, {
    method: "POST",
    headers: { ...client, Accept: "application/json", "Content-Type": "application/json", "Accept-Language": locale },
    body: JSON.stringify({ refresh_token: refreshToken }),
    cache: "no-store",
  })
    .then(async (r) => (r.ok ? ((await r.json()).data as Tokens) : null))
    .catch(() => null);
  inflight.set(refreshToken, { at: now, promise });
  return promise;
}

function cookieOptions(expiresAt: string, early = 0) {
  return {
    httpOnly: true, sameSite: "lax" as const, secure: process.env.NODE_ENV === "production", path: "/",
    maxAge: Math.max(1, Math.floor((Date.parse(expiresAt) - Date.now()) / 1000) - early),
  };
}

export async function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const segment = pathname.split("/")[1] ?? "";
  if (!hasLocale(segment)) {
    const url = request.nextUrl.clone();
    url.pathname = `/${preferredLocale(request)}${pathname === "/" ? "" : pathname}`;
    return NextResponse.redirect(url);
  }

  const locale = segment;
  const area = protectedArea(pathname.slice(locale.length + 1));
  if (!area) return;

  const names = COOKIES[area];
  if (request.cookies.get(names.at)) return;

  const loginUrl = new URL(`/${locale}/${area === "admin" ? "admin/login" : "login"}`, request.url);
  loginUrl.searchParams.set("next", pathname + search);

  const refresh = request.cookies.get(names.rt)?.value;
  const ip = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  const ua = request.headers.get("user-agent");
  const client = { ...(ip ? { "X-Forwarded-For": ip } : {}), ...(ua ? { "User-Agent": ua } : {}) };
  const tokens = refresh ? await refreshOnce(refresh, locale, client) : null;
  if (!tokens) {
    const res = NextResponse.redirect(loginUrl);
    res.cookies.delete(names.rt);
    return res;
  }

  // الرمز الجديد يصل لعرض هذه الصفحة عبر ترويسة الطلب، وللمتصفح عبر Set-Cookie
  const headers = new Headers(request.headers);
  request.cookies.set(names.at, tokens.access_token);
  request.cookies.set(names.rt, tokens.refresh_token);
  headers.set("cookie", request.cookies.toString());
  const res = NextResponse.next({ request: { headers } });
  res.cookies.set(names.at, tokens.access_token, cookieOptions(tokens.access_expires_at, 30));
  res.cookies.set(names.rt, tokens.refresh_token, cookieOptions(tokens.refresh_expires_at));
  return res;
}

export const config = {
  // تجاهل ملفات Next الداخلية والملفات الثابتة (التي فيها نقطة)
  matcher: ["/((?!_next|.*\\..*).*)"],
};
