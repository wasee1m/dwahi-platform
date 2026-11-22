import "server-only";
import { cookies } from "next/headers";
import type { Me } from "./client";

// محدد الحساب "شخصي / الشركة" (06 §2). الاختيار في كوكي، والـ API يتحقق من العضوية في كل طلب.
export const ACCOUNT_COOKIE = "dw_account";
export const PERSONAL = "personal";

export type SelectedAccount = {
  /** "personal" أو معرّف الشركة */
  value: string;
  /** ما يُرسل عند الإنشاء: null = شخصي */
  organizationId: string | null;
  /** لاستعلام القوائم. فارغ لمن لا شركة له. */
  query: string;
};

export async function selectedAccount(me: Me): Promise<SelectedAccount> {
  const orgs = me.organizations;
  if (!orgs.length) return { value: PERSONAL, organizationId: null, query: "" };
  const saved = (await cookies()).get(ACCOUNT_COOKIE)?.value;
  const value = saved === PERSONAL ? PERSONAL : (orgs.find((o) => o.id === saved)?.id ?? orgs[0].id);
  return { value, organizationId: value === PERSONAL ? null : value, query: `account=${value}` };
}
