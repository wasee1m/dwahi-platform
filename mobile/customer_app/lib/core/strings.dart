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
  String get browseAsGuest => _t('تصفح كزائر', 'Browse as a guest');
  String get signInToContinue => _t('سجّل الدخول لإرسال الطلب ومتابعة العروض.', 'Sign in to send the request and follow your quotes.');
  String get signIn => _t('تسجيل الدخول', 'Sign in');
  String get retry => _t('إعادة المحاولة', 'Retry');
  String get empty => _t('لا توجد نتائج.', 'No results.');
  String get save => _t('حفظ', 'Save');
  String get cancel => _t('إلغاء', 'Cancel');

  // التبويبات (07 §1.1)
  String get tabHome => _t('الرئيسية', 'Home');
  String get tabEquipment => _t('المعدات', 'Equipment');
  String get tabParts => _t('القطع', 'Parts');
  String get tabRequests => _t('طلباتي', 'My requests');
  String get tabAccount => _t('حسابي', 'Account');

  // M-06
  String get searchAll => _t('ابحث عن معدة أو رقم قطعة', 'Search equipment or a part number');
  String get requestService => _t('اطلب صيانة', 'Request a repair');
  String get findPart => _t('ابحث عن قطعة', 'Find a part');
  String get browseEquipment => _t('تصفح المعدات', 'Browse equipment');
  String get needsAction => _t('بحاجة لإجراء منك', 'Needs your action');
  String get featured => _t('معدات مميزة', 'Featured equipment');
  String get soon => _t('قريباً', 'Soon');

  // M-07، M-08
  String get filter => _t('فلترة', 'Filter');
  String get all => _t('الكل', 'All');
  String get kindMachine => _t('آليات', 'Machines');
  String get kindComponent => _t('مكوّنات رئيسية', 'Major components');
  String get kindScrap => _t('سكراب', 'Scrap');
  String get condition => _t('الحالة', 'Condition');
  String get brand => _t('الماركة', 'Brand');
  String get sort => _t('الترتيب', 'Sort');
  String get newest => _t('الأحدث', 'Newest');
  String get priceAsc => _t('السعر: الأقل', 'Price: low to high');
  String get priceDesc => _t('السعر: الأعلى', 'Price: high to low');
  String get apply => _t('تطبيق', 'Apply');
  String get clear => _t('مسح', 'Clear');
  String get priceOnRequest => _t('السعر عند الطلب', 'Price on request');
  String get exVat => _t('قبل الضريبة', 'excl. VAT');
  String get year => _t('السنة', 'Year');
  String get hours => _t('ساعات التشغيل', 'Hours');
  String get warranty => _t('الضمان', 'Warranty');
  String months(int n) => _t('$n شهراً', '$n months');
  String get specs => _t('المواصفات', 'Specifications');
  String get description => _t('الوصف', 'Description');
  String get missingParts => _t('ما ينقصها', 'Missing parts');
  String get requestQuote => _t('اطلب عرض سعر', 'Request a quote');
  String get whatsapp => _t('واتساب', 'WhatsApp');
  String whatsappAbout(String s) => _t('مرحباً، أستفسر عن $s', 'Hello, I am asking about $s');
  String get reserved => _t('محجوزة', 'Reserved');
  String get sold => _t('مباعة', 'Sold');
  String get share => _t('مشاركة', 'Share');
  String conditionName(String c) => switch (c) {
        'new' => _t('جديد', 'New'),
        'used' => _t('مستعمل', 'Used'),
        'rebuilt' => _t('مجدد', 'Rebuilt'),
        _ => _t('سكراب', 'Scrap'),
      };

  // M-09، M-10
  String get partSearchHint => _t('رقم القطعة، مثل 1R-0750', 'Part number, like 1R-0750');
  String get partSearchIntro => _t('اكتب الرقم كما هو على القطعة، بالشرطات أو دونها.', 'Type the number as it is on the part, with or without dashes.');
  String matchedAlternative(String n) => _t('مطابقة للرقم البديل $n', 'Matches alternative number $n');
  String get alternatives => _t('الأرقام البديلة', 'Alternative numbers');
  String get compatibleWith => _t('متوافقة مع', 'Fits');
  String get quantity => _t('الكمية', 'Quantity');
  String get requestQuantity => _t('اطلب كمية أو عرض سعر', 'Request a quantity or a quote');
  String get cartSoon => _t('السلة والطلب المباشر قريباً.', 'Cart and direct ordering coming soon.');
  String get returnable => _t('قابلة للإرجاع', 'Returnable');
  String get notReturnable => _t('غير قابلة للإرجاع', 'Not returnable');
  String availability(String a) => switch (a) {
        'available' => _t('متوفرة', 'In stock'),
        'limited' => _t('كمية محدودة', 'Limited stock'),
        'out_of_stock' => _t('غير متوفرة حالياً', 'Out of stock'),
        _ => _t('حسب الطلب', 'On request'),
      };

  // M-16، M-18، M-24
  String get quotes => _t('العروض', 'Quotes');
  String get service => _t('الصيانة', 'Service');
  String get partsOrders => _t('القطع', 'Parts');
  String get newRequest => _t('طلب عرض سعر', 'Quote request');
  String get noRequests => _t('لا طلبات هنا بعد.', 'No requests here yet.');
  String rfqStatus(String s) => switch (s) {
        'submitted' => _t('مُرسل', 'Submitted'),
        'in_review' => _t('قيد المراجعة', 'Under review'),
        'quoted' => _t('وصل العرض', 'Quote received'),
        'won' => _t('مقبول', 'Accepted'),
        'lost' => _t('مغلق', 'Closed'),
        _ => _t('ملغى', 'Cancelled'),
      };
  String quoteStatus(String s) => switch (s) {
        'sent' => _t('بانتظار ردك', 'Awaiting your reply'),
        'accepted' => _t('مقبول', 'Accepted'),
        'rejected' => _t('مرفوض', 'Declined'),
        'revision_requested' => _t('طلبت تعديله', 'Changes requested'),
        'expired' => _t('منتهي', 'Expired'),
        _ => _t('نسخة سابقة', 'Earlier version'),
      };
  String typeName(String t) => switch (t) { 'equipment' => _t('معدات', 'Equipment'), 'parts' => _t('قطع غيار', 'Spare parts'), _ => _t('صيانة', 'Service') };
  String daysLeftText(int n) => n <= 0 ? _t('آخر يوم اليوم', 'Last day today') : _t('متبقٍ $n يوم', '$n days left');
  String get validUntil => _t('صالح حتى', 'Valid until');
  String get subtotal => _t('المجموع قبل الخصم', 'Subtotal');
  String get discount => _t('الخصم', 'Discount');
  String get vat => _t('ضريبة القيمة المضافة', 'VAT');
  String get total => _t('الإجمالي', 'Total');
  String get pdf => _t('PDF', 'PDF');
  String get accept => _t('قبول', 'Accept');
  String get reject => _t('رفض', 'Decline');
  String get askChanges => _t('طلب تعديل', 'Ask for changes');
  String get sendForApproval => _t('إرسال للاعتماد', 'Send for approval');
  String get approvalSent => _t('أرسلنا العرض لمالك الحساب لاعتماده.', 'Sent to the account owner for approval.');
  String get renew => _t('اطلب تجديد العرض', 'Ask to renew the quote');
  String get cancelRequest => _t('إلغاء الطلب', 'Cancel request');
  String get lpoNumber => _t('رقم أمر الشراء (اختياري)', 'Purchase order number (optional)');
  String get lpoAttach => _t('إرفاق أمر الشراء', 'Attach purchase order');
  String get reason => _t('السبب (اختياري)', 'Reason (optional)');
  String get changesNote => _t('ما التعديل المطلوب؟', 'What should change?');
  String get send => _t('إرسال', 'Send');
  String get accepted => _t('قبلت العرض. سيتواصل معك فريقنا لإكمال الشراء.', 'You accepted. Our team will contact you to complete the purchase.');
  String get noQuoteYet => _t('نراجع طلبك ونرسل العرض قريباً.', 'We are reviewing your request and will send a quote soon.');
  String get requestItems => _t('البنود', 'Items');
  String get pricesNote => _t('الأسعار قبل الضريبة، والضريبة لكل سطر.', 'Prices exclude VAT; VAT is per line.');
  String get rfqIntro => _t('أضف ما تحتاجه ونرد بعرض سعر رسمي.', 'Add what you need and we will reply with a formal quote.');
  String get addFromCatalog => _t('ابحث في الكتالوج', 'Search the catalog');
  String get addUnlisted => _t('قطعة غير مدرجة', 'Unlisted part');
  String get unlistedHint => _t('رقم القطعة أو وصفها', 'Part number or description');
  String get takePhoto => _t('صورة بالكاميرا', 'Take photo');
  String get fromGallery => _t('من المعرض', 'From gallery');
  String get fromFiles => _t('من الملفات', 'From files');
  String get cylinderSpecs => _t('سلندر هيدروليك: المواصفات', 'Hydraulic cylinder: specifications');
  String get bore => _t('القطر الداخلي (مم)', 'Bore (mm)');
  String get rod => _t('قطر العمود (مم)', 'Rod (mm)');
  String get stroke => _t('الشوط (مم)', 'Stroke (mm)');
  String get mounting => _t('نوع التثبيت', 'Mounting');
  String get pressure => _t('ضغط التشغيل (بار)', 'Pressure (bar)');
  String get notes => _t('ملاحظات', 'Notes');
  String get exportCountry => _t('للتصدير: بلد الوجهة (رمز مثل SY)', 'For export: destination country (code like SY)');
  String get sendRequest => _t('أرسل الطلب', 'Send request');
  String get addItemFirst => _t('أضف بنداً واحداً على الأقل.', 'Add at least one item.');
  String get requestSent => _t('أرسلنا طلبك. ستصلك رسالة عند وصول العرض.', 'Request sent. You will be notified when the quote is ready.');
  String get uploading => _t('جارٍ الرفع...', 'Uploading...');

  // M-20، M-21
  String get messages => _t('المحادثة', 'Conversation');
  String get messageHint => _t('اكتب رسالتك للفريق', 'Write to our team');
  String get team => _t('فريق ضواحي البادية', 'DWAHI-ALBADIA team');
  String get noMessages => _t('لا رسائل بعد.', 'No messages yet.');
  String get notifications => _t('الإشعارات', 'Notifications');
  String get markAllRead => _t('تعليم الكل كمقروء', 'Mark all as read');
  String get noNotifications => _t('لا إشعارات.', 'No notifications.');
  String get today => _t('اليوم', 'Today');
  String get yesterday => _t('أمس', 'Yesterday');
  String get older => _t('أقدم', 'Earlier');

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
