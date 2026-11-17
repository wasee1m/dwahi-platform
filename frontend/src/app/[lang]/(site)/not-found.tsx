import Link from "next/link";
import { Container } from "@/components/ui";

// W-17 صفحة غير موجودة. not-found لا يستقبل اللغة، فالرسالة باللغتين.
export default function NotFound() {
  return (
    <Container className="py-24 text-center">
      <p className="num font-display text-6xl font-extrabold text-sun-text">404</p>
      <h1 className="mt-4 text-2xl" lang="ar">الصفحة غير موجودة</h1>
      <p className="mt-1 font-display text-lg" lang="en">Page not found</p>
      <p className="mt-4 text-ink-muted">ربما تغير الرابط أو حُذفت الصفحة. · The link may have changed or the page was removed.</p>
      <div className="mt-8 flex justify-center gap-3">
        <Link href="/ar" hrefLang="ar" className="rounded-sm bg-sun px-5 py-2.5 font-bold text-on-sun">الصفحة الرئيسية</Link>
        <Link href="/en" hrefLang="en" className="rounded-sm border border-ink px-5 py-2.5 font-bold">Home page</Link>
      </div>
    </Container>
  );
}
