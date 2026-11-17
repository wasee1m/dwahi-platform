import { fill } from "@/i18n/fill";
import type { AppDictionary } from "@/i18n/get-dictionary";
import type { FormState } from "./actions";

// رسائل أخطاء الدخول برموز الـ API الثابتة (04 §4.2)، مع الوقت المتبقي والمحاولات (US-06)
export function authErrorMessage(error: FormState["error"], t: AppDictionary["auth"]): string | undefined {
  if (!error) return undefined;
  const details = "details" in error ? error.details : undefined;
  const minutes = Math.max(1, Math.ceil((details?.retry_after ?? 60) / 60));
  switch (error.code) {
    case "OTP_INVALID": return fill(t.attemptsLeft, { n: details?.attempts_left ?? 0 });
    case "OTP_LOCKED": return fill(t.lockedFor, { m: minutes });
    case "OTP_THROTTLED": return fill(t.throttledFor, { m: minutes });
    case "RATE_LIMITED": return fill(t.throttledFor, { m: 1 });
    case "OTP_EXPIRED": return t.expired;
    case "INVALID_PHONE": return t.invalidPhone;
    case "INVALID_CREDENTIALS": return t.invalidCredentials;
    case "ACCOUNT_SUSPENDED": return t.suspended;
    case "VALIDATION_FAILED": return ("message" in error && error.message) || t.genericError;
    default: return t.genericError;
  }
}
