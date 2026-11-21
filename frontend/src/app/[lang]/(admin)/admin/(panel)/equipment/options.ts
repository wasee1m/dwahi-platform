import "server-only";
import type { Locale } from "@/i18n/config";
import { authed } from "@/lib/auth/client";
import { listBranches } from "@/lib/api";
import type { Model } from "../catalog-setup/SetupForms";

// قوائم الاختيار في محرر المعروض: الموديلات (A-03) والفروع
export async function editorOptions(lang: Locale) {
  const [models, branches] = await Promise.all([authed<Model[]>("admin", lang, "/admin/models"), listBranches(lang)]);
  return {
    models: (models.ok ? models.data : []).map((m) => ({ value: m.id, label: `${m.brand} ${m.model_name}` })).sort((a, b) => a.label.localeCompare(b.label)),
    branches: branches.map((b) => ({ value: b.code, label: b.name })),
  };
}
