// شعار "الضاد" (brand/dwahi-mark.svg) مع الاسم. الألوان من رموز 01 §9.1.
export function LogoMark({ size = 40, inverse = false }: { size?: number; inverse?: boolean }) {
  const stroke = inverse ? "var(--on-night)" : "var(--ink)";
  return (
    <svg viewBox="0 0 200 200" width={size} height={size} aria-hidden="true" focusable="false">
      <g fill="none" stroke={stroke} strokeWidth="24" strokeLinejoin="round">
        <rect x="100" y="76" width="68" height="46" rx="23" />
        <path d="M123,122 H94 C94,180 32,180 32,118" />
      </g>
      <path d="M20,119 L24,106 L28,119 Z M28,119 L32,106 L36,119 Z M36,119 L40,106 L44,119 Z" fill={stroke} />
      <circle cx="134" cy="40" r="17" fill="var(--sun)" />
    </svg>
  );
}

export function Logo({ inverse = false }: { inverse?: boolean }) {
  return (
    <span className="flex items-center gap-2.5">
      <LogoMark size={40} inverse={inverse} />
      <span className="flex flex-col leading-none">
        <span className="font-display text-lg font-extrabold" lang="ar">ضواحي البادية</span>
        <span className="font-display mt-1 text-[10px] font-extrabold tracking-[0.22em]" lang="en">DWAHI-ALBADIA</span>
      </span>
    </span>
  );
}
