// عند تعذر الوصول للـ API، الأجزاء الثانوية (التذييل، الماركات، البنرات) تختفي بدل أن تُسقط الصفحة كلها.
// الصفحات التي محتواها الأساسي من الـ API تترك الخطأ يصل لحدود error.tsx.
export async function safe<T>(promise: Promise<T>, fallback: T): Promise<T> {
  try {
    return await promise;
  } catch (error) {
    console.error("[api]", error instanceof Error ? error.message : error);
    return fallback;
  }
}
