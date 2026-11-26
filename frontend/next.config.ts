import type { NextConfig } from "next";

// صور الكتالوج من تخزين الـ API (محلياً)، ومن الـ CDN في الإنتاج (04 §8.2)
const imageHost = new URL(process.env.API_URL ?? "http://localhost:8000/api/v1");
// مُحسّن الصور يرفض العناوين المحلية حمايةً من SSRF. في التطوير المحلي فقط يحمّل المتصفح الصورة
// مباشرة، فتبقى الحماية كما هي ولا يلزم images.dangerouslyAllowLocalIP
const localDevImages = process.env.NODE_ENV === "development" && ["localhost", "127.0.0.1", "[::1]"].includes(imageHost.hostname);

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  poweredByHeader: false,
  // ترويسات الأمان (NFR-06، OWASP ASVS V14): لا تضمين في إطار، ولا تخمين للنوع، وHTTPS دائماً في الإنتاج،
  // والميكروفون (الملاحظة الصوتية) والموقع (دبوس العنوان) لهذا الموقع فقط
  async headers() {
    return [{
      source: "/:path*",
      headers: [
        { key: "X-Frame-Options", value: "DENY" },
        { key: "Content-Security-Policy", value: "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'" },
        { key: "X-Content-Type-Options", value: "nosniff" },
        { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
        { key: "Permissions-Policy", value: "camera=(), microphone=(self), geolocation=(self), payment=()" },
        ...(process.env.NODE_ENV === "production" ? [{ key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" }] : []),
      ],
    }];
  },
  // صور المعدات (حتى 10MB) وملفات استيراد القطع تمر عبر Server Actions (04 §8.2)
  experimental: { serverActions: { bodySizeLimit: "110mb" } },
  images: {
    unoptimized: localDevImages,
    remotePatterns: [{ protocol: imageHost.protocol.replace(":", "") as "http" | "https", hostname: imageHost.hostname, port: imageHost.port, pathname: "/storage/**" }],
  },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
