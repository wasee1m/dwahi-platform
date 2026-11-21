// مساعدات طلب العرض المشتركة بين بوابة العميل ولوحة الإدارة

/** مواصفات السلندر بأسمائها (FR-RFQ-01)، والمفتاح كما يحفظه الـ API */
export function specLines(specs: Record<string, string | number>, f: { bore: string; rod: string; stroke: string; mounting: string; pressure: string }): string {
  const label: Record<string, string> = { bore_mm: f.bore, rod_mm: f.rod, stroke_mm: f.stroke, mounting: f.mounting, pressure_bar: f.pressure };
  return ["bore_mm", "rod_mm", "stroke_mm", "mounting", "pressure_bar"].filter((k) => specs[k] !== undefined && specs[k] !== "")
    .map((k) => `${label[k]}: ${specs[k]}`).join(" · ");
}
