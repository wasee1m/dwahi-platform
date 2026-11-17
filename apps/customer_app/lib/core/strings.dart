import 'package:flutter/widgets.dart';

/// نصوص التطبيق بالعربية والإنجليزية (NFR-14). تُنقل لملفات ARB من مصدر الترجمة المشترك (04 §13)
/// عند إعداد حزمة i18n، والمفاتيح نفسها.
class S {
  S(this.locale);
  final Locale locale;

  static S of(BuildContext context) => S(Localizations.localeOf(context));

  bool get _ar => locale.languageCode != 'en';
  String _t(String ar, String en) => _ar ? ar : en;

  String get appName => _t('ضواحي البادية', 'DWAHI-ALBADIA');
  String get welcomeTitle => _t('معدات وقطع وصيانة في تطبيق واحد', 'Equipment, parts and repairs in one app');
  String get welcomeText => _t('تابع طلباتك وعروضك وصيانة معداتك من جوالك.', 'Follow your orders, quotes and repairs from your phone.');
  String get start => _t('ابدأ', 'Get started');
  String get phoneTitle => _t('رقم جوالك', 'Your mobile number');
  String get phoneIntro => _t('نرسل لك رمز تحقق. لا تحتاج كلمة مرور.', 'We will text you a code. No password needed.');
  String get phoneHint => _t('9XXX XXXX', '9XXX XXXX');
  String get sendCode => _t('أرسل الرمز', 'Send code');
  String get codeTitle => _t('رمز التحقق', 'Verification code');
  String codeSentTo(String phone) => _t('أرسلنا رمزاً من 6 أرقام إلى $phone', 'We sent a 6-digit code to $phone');
  String get verify => _t('تحقق', 'Verify');
  String get resend => _t('إعادة الإرسال', 'Resend');
  String resendIn(int s) => _t('إعادة الإرسال بعد $s ثانية', 'Resend in $s s');
  String attemptsLeft(int n) => _t('الرمز غير صحيح. محاولات متبقية: $n', 'Wrong code. Attempts left: $n');
  String lockedFor(int m) => _t('محاولات خاطئة كثيرة. حاول بعد $m دقيقة.', 'Too many wrong attempts. Try again in $m minutes.');
  String throttledFor(int m) => _t('طلبت رموزاً كثيرة. انتظر $m دقيقة.', 'Too many codes requested. Wait $m minutes.');
  String get expired => _t('انتهت صلاحية الرمز. اطلب رمزاً جديداً.', 'The code expired. Request a new one.');
  String get invalidPhone => _t('اكتب رقم جوال صحيحاً.', 'Enter a valid mobile number.');
  String get genericError => _t('حدث خطأ. حاول مرة أخرى.', 'Something went wrong. Please try again.');
  String get offline => _t('لا يوجد اتصال بالإنترنت.', 'No internet connection.');
  String get onboardingTitle => _t('أكمل ملفك', 'Complete your profile');
  String get fullName => _t('الاسم الكامل', 'Full name');
  String get individual => _t('فرد', 'Individual');
  String get company => _t('شركة', 'Company');
  String get companyName => _t('اسم الشركة', 'Company name');
  String get governorate => _t('المحافظة', 'Governorate');
  String get acceptTerms => _t('أوافق على الشروط والأحكام', 'I accept the terms and conditions');
  String get acceptPrivacy => _t('أوافق على سياسة الخصوصية', 'I accept the privacy policy');
  String get marketing => _t('أرغب في استلام العروض والأخبار', 'Send me offers and news');
  String get createAccount => _t('إنشاء الحساب', 'Create account');
  String get required => _t('مطلوب', 'Required');
  String hello(String name) => _t('أهلاً $name', 'Hello $name');
  String get comingSoon => _t('الكتالوج والطلبات والصيانة في التحديثات القادمة.', 'Catalog, orders and repairs arrive in the next updates.');
  String get myAccount => _t('حسابي', 'My account');
  String get logout => _t('تسجيل الخروج', 'Sign out');
  String get language => _t('اللغة', 'Language');

  static const governorates = {
    'muscat': ('مسقط', 'Muscat'),
    'dhofar': ('ظفار', 'Dhofar'),
    'musandam': ('مسندم', 'Musandam'),
    'buraimi': ('البريمي', 'Al Buraimi'),
    'dakhiliyah': ('الداخلية', 'Ad Dakhiliyah'),
    'north_batinah': ('شمال الباطنة', 'North Al Batinah'),
    'south_batinah': ('جنوب الباطنة', 'South Al Batinah'),
    'south_sharqiyah': ('جنوب الشرقية', 'South Ash Sharqiyah'),
    'north_sharqiyah': ('شمال الشرقية', 'North Ash Sharqiyah'),
    'dhahirah': ('الظاهرة', 'Adh Dhahirah'),
    'wusta': ('الوسطى', 'Al Wusta'),
  };

  String governorateName(String key) {
    final g = governorates[key];
    return g == null ? key : (_ar ? g.$1 : g.$2);
  }
}
