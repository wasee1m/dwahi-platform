import "server-only";
import type { Locale } from "./config";

const dictionaries = {
  ar: () => import("./dictionaries/ar.json").then((m) => m.default),
  en: () => import("./dictionaries/en.json").then((m) => m.default),
};

// نصوص الحساب والإدارة في ملف منفصل، فلا تُحمَّل مع صفحات الموقع العام
const appDictionaries = {
  ar: () => import("./dictionaries/app.ar.json").then((m) => m.default),
  en: () => import("./dictionaries/app.en.json").then((m) => m.default),
};

export type Dictionary = Awaited<ReturnType<(typeof dictionaries)["ar"]>>;
export type AppDictionary = Awaited<ReturnType<(typeof appDictionaries)["ar"]>>;

export const getDictionary = async (locale: Locale): Promise<Dictionary> => dictionaries[locale]();
export const getAppDictionary = async (locale: Locale): Promise<AppDictionary> => appDictionaries[locale]();

export { fill } from "./fill";
