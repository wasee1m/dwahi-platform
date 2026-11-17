// اللغتان (NFR-14): العربية الافتراضية ومعها الإنجليزية، والاتجاه يتبع اللغة
export const locales = ["ar", "en"] as const;
export type Locale = (typeof locales)[number];
export const defaultLocale: Locale = "ar";

export const hasLocale = (value: string): value is Locale =>
  (locales as readonly string[]).includes(value);

export const dirOf = (locale: Locale) => (locale === "ar" ? "rtl" : "ltr");

// التوقيت Asia/Muscat والأرقام الغربية افتراضياً (NFR-21، Q-15)
export const intlLocale = (locale: Locale) => (locale === "ar" ? "ar-OM-u-nu-latn" : "en-OM");
