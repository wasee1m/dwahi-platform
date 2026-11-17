// "{name}" ← القيمة. منفصلة عن get-dictionary (خادم فقط) لأن مكونات المتصفح تستخدمها أيضاً.
export function fill(template: string, values: Record<string, string | number>): string {
  return template.replace(/\{(\w+)\}/g, (_, k) => String(values[k] ?? ""));
}
