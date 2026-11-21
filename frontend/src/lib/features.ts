// مفاتيح الإطلاق (09 §7): الموقع العام يُطلق مبكراً دون البوابة، فأزرار "اطلب السعر" تفتح نموذج الاستفسار
// حتى تُفعَّل البوابة (S5). مفعلة في التطوير، ومطفأة في الإنتاج ما لم يُضبط PORTAL_ENABLED=true.
export function portalEnabled(): boolean {
  const v = process.env.PORTAL_ENABLED;
  return v === undefined ? process.env.NODE_ENV !== "production" : v === "true";
}
