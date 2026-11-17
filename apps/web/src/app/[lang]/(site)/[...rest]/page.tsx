import { notFound } from "next/navigation";

// أي رابط غير معروف تحت /ar أو /en يعرض صفحة 404 داخل إطار الموقع
export default function CatchAll() {
  notFound();
}
