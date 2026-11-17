"use client";

import { Container } from "@/components/ui";

// W-17 خطأ غير متوقع: رسالة ودية + إعادة المحاولة + رمز التتبع (06 §5)
export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <Container className="py-24 text-center">
      <h1 className="text-2xl" lang="ar">حدث خطأ غير متوقع</h1>
      <p className="mt-1 font-display text-lg" lang="en">Something went wrong</p>
      <p className="mt-4 text-ink-muted">نعتذر. حاول مرة أخرى بعد قليل. · Please try again shortly.</p>
      <button onClick={reset} className="mt-8 rounded-sm bg-sun px-6 py-3 font-bold text-on-sun hover:bg-sun-deep">
        إعادة المحاولة · Try again
      </button>
      {error.digest && <p className="mt-6 text-xs text-ink-muted">Trace: <span className="num">{error.digest}</span></p>}
    </Container>
  );
}
