import type { NextConfig } from "next";

// صور الكتالوج من تخزين الـ API (محلياً)، ومن الـ CDN في الإنتاج (04 §8.2)
const imageHost = new URL(process.env.API_URL ?? "http://localhost:8000/api/v1");

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  poweredByHeader: false,
  // صور المعدات (حتى 10MB) وملفات استيراد القطع تمر عبر Server Actions (04 §8.2)
  experimental: { serverActions: { bodySizeLimit: "12mb" } },
  images: {
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
