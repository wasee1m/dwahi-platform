import "server-only";
import { cookies } from "next/headers";

// جلسة الويب (04 §5): الخادم (Next.js) يحمل رموز الـ API في كوكيز HttpOnly، فلا يرى المتصفح أي رمز.
// منطقتان منفصلتان: العميل (/account) والإدارة (/admin)، ولكل منهما جلسة مستقلة.
export type Area = "customer" | "admin";

export const COOKIE = {
  customer: { at: "dw_at", rt: "dw_rt" },
  admin: { at: "dwa_at", rt: "dwa_rt" },
} as const;

export type TokenPayload = {
  access_token: string;
  access_expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  session_id: string;
};

const base = { httpOnly: true, sameSite: "lax" as const, secure: process.env.NODE_ENV === "production", path: "/" };

// كوكي الوصول تنتهي قبل الرمز بـ 30 ثانية، فيجدده proxy.ts قبل أن يرفضه الـ API
export function cookieSpecs(area: Area, t: TokenPayload) {
  const now = Date.now();
  return [
    { name: COOKIE[area].at, value: t.access_token, ...base, maxAge: Math.max(1, Math.floor((Date.parse(t.access_expires_at) - now) / 1000) - 30) },
    { name: COOKIE[area].rt, value: t.refresh_token, ...base, maxAge: Math.max(1, Math.floor((Date.parse(t.refresh_expires_at) - now) / 1000)) },
  ];
}

/** للاستخدام في Server Actions فقط (الكتابة على الكوكيز غير مسموحة أثناء العرض) */
export async function saveSession(area: Area, t: TokenPayload) {
  const jar = await cookies();
  for (const c of cookieSpecs(area, t)) jar.set(c);
}

export async function clearSession(area: Area) {
  const jar = await cookies();
  jar.delete(COOKIE[area].at);
  jar.delete(COOKIE[area].rt);
}

export async function accessToken(area: Area): Promise<string | null> {
  return (await cookies()).get(COOKIE[area].at)?.value ?? null;
}
