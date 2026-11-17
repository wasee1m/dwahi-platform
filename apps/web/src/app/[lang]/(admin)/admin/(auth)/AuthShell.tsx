import type { ReactNode } from "react";
import { Logo } from "@/components/Logo";

// إطار صفحات دخول الموظفين: الشعار فوق بطاقة في منتصف الصفحة
export function AuthShell({ title, intro, children }: { title: string; intro?: ReactNode; children: ReactNode }) {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
      <Logo />
      <div className="mt-8 w-full max-w-md rounded-md border border-sand-300 bg-sand-0 p-7">
        <h1 className="text-2xl">{title}</h1>
        {intro && <div className="mt-2 text-ink-muted">{intro}</div>}
        <div className="mt-6">{children}</div>
      </div>
    </main>
  );
}
