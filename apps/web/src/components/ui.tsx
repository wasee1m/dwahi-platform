import Link from "next/link";
import type { ComponentProps, ReactNode } from "react";

// أزرار وعناوين مشتركة. الزر الرئيسي كهرماني بنص داكن (on-sun)، ولا أبيض على الكهرماني (01 §9.1).
const base = "inline-flex items-center justify-center gap-2 rounded-sm px-5 py-3 font-bold transition";
export const buttonStyles = {
  primary: `${base} bg-sun text-on-sun hover:bg-sun-deep`,
  secondary: `${base} border border-ink bg-transparent text-ink hover:bg-sand-200`,
  night: `${base} bg-night text-on-night hover:opacity-90`,
};

export function ButtonLink({ variant = "primary", className = "", ...props }: ComponentProps<typeof Link> & { variant?: keyof typeof buttonStyles }) {
  return <Link {...props} className={`${buttonStyles[variant]} ${className}`} />;
}

export function PageHeader({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="border-b border-sand-300 bg-sand-0">
      <div className="mx-auto max-w-6xl px-4 py-10">
        <h1 className="text-3xl md:text-4xl">{title}</h1>
        {children && <div className="mt-3 max-w-3xl text-ink-muted">{children}</div>}
      </div>
    </div>
  );
}

export function Container({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto max-w-6xl px-4 ${className}`}>{children}</div>;
}

export function Skeleton({ className = "" }: { className?: string }) {
  return <div className={`animate-pulse rounded-md bg-sand-200 ${className}`} aria-hidden="true" />;
}

export function CardGridSkeleton({ count = 6 }: { count?: number }) {
  return (
    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: count }, (_, i) => <Skeleton key={i} className="h-80" />)}
    </div>
  );
}
