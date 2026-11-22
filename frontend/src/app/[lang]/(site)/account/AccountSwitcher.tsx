"use client";

import { useRef } from "react";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { Me } from "@/lib/auth/client";
import { chooseAccount } from "./portal-actions";

// محدد الحساب "شخصي / الشركة" في إطار البوابة (06 §2)
export function AccountSwitcher({ lang, t, value, companies }: { lang: string; t: AppDictionary["account"]; value: string; companies: Me["organizations"] }) {
  const form = useRef<HTMLFormElement>(null);
  return (
    <form ref={form} action={chooseAccount} className="mt-3">
      <input type="hidden" name="lang" value={lang} />
      <label className="block text-xs font-semibold text-ink-muted">
        {t.switcher}
        <select name="account" defaultValue={value} onChange={() => form.current?.requestSubmit()}
          className="mt-1 w-full rounded-sm border border-sand-300 bg-sand-100 px-2 py-1.5 text-sm text-ink">
          {companies.map((c) => <option key={c.id} value={c.id}>{c.name} · {c.role === "owner" ? t.owner : t.member}</option>)}
          <option value="personal">{t.personal}</option>
        </select>
      </label>
    </form>
  );
}
