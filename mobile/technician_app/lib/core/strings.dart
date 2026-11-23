import 'package:flutter/widgets.dart';

/// نصوص تطبيق الفني بالعربية والإنجليزية (NFR-14)، على نمط تطبيق العملاء
class S {
  S(this.locale);
  final Locale locale;

  static S of(BuildContext context) => S(Localizations.localeOf(context));

  bool get _ar => locale.languageCode != 'en';
  String _t(String ar, String en) => _ar ? ar : en;

  String get appName => _t('تطبيق الفني', 'Technician app');
  String get genericError => _t('حدث خطأ. حاول مرة أخرى.', 'Something went wrong. Please try again.');
  String get offline => _t('لا يوجد اتصال بالإنترنت.', 'No internet connection.');
  String get retry => _t('إعادة المحاولة', 'Retry');
  String get cancel => _t('إلغاء', 'Cancel');
  String get save => _t('حفظ', 'Save');
  String get close => _t('إغلاق', 'Close');

  // T-01
  String get loginTitle => _t('دخول الفني', 'Technician sign-in');
  String get loginField => _t('البريد أو رقم الجوال', 'Email or mobile number');
  String get password => _t('كلمة المرور', 'Password');
  String get activationCode => _t('رمز تفعيل الجهاز', 'Device activation code');
  String get activationHint => _t('من المنسق، لمرة واحدة على هذا الجهاز', 'From the coordinator, once on this device');
  String get deviceBound => _t('هذا الجهاز مفعّل لحسابك.', 'This device is activated for your account.');
  String get useAnotherCode => _t('تفعيل الجهاز برمز جديد', 'Activate with a new code');
  String get signIn => _t('دخول', 'Sign in');
  String get badCredentials => _t('البيانات غير صحيحة.', 'Wrong sign-in details.');
  String get deviceNotRegistered => _t('هذا الجهاز غير مفعّل. اطلب رمز تفعيل من المنسق.', 'This device is not activated. Ask the coordinator for an activation code.');
  String get codeInvalid => _t('رمز التفعيل غير صحيح أو مستخدم.', 'The activation code is wrong or already used.');

  // T-02
  String get tasks => _t('مهامي', 'My tasks');
  String get today => _t('اليوم', 'Today');
  String get upcoming => _t('القادمة', 'Upcoming');
  String get noTasksToday => _t('لا مهام لك اليوم.', 'No tasks for you today.');
  String get noUpcoming => _t('لا مهام قادمة.', 'No upcoming tasks.');
  String get scan => _t('مسح ملصق المعدة', 'Scan the item label');
  String get cardNumber => _t('رقم بطاقة الاستلام', 'Receiving card number');
  String get open => _t('فتح', 'Open');
  String get notYourTask => _t('لا مهمة مسندة إليك على هذه المعدة.', 'No task on this item is assigned to you.');
  String get offlineBanner => _t('تعمل دون اتصال. التغييرات محفوظة على الجهاز وتُرسل عند عودة الاتصال.',
      'You are offline. Changes are saved on this device and sent when the connection returns.');
  String pendingCount(int n) => _t('بانتظار الإرسال: $n', 'Waiting to send: $n');
  String rejectedCount(int n) => _t('مرفوض: $n', 'Rejected: $n');
  String get urgent => _t('عاجل', 'Urgent');
  String get notReceived => _t('لم تُستلم بعد', 'Not received yet');
  String get assistant => _t('مساعد', 'Assistant');
  String lastUpdated(String t) => _t('آخر تحديث $t', 'Last updated $t');

  String status(String s) => switch (s) {
        'scheduled' => _t('مجدولة', 'Scheduled'),
        'in_progress' => _t('جارية', 'In progress'),
        'on_hold' => _t('متوقفة', 'On hold'),
        'work_completed' => _t('أُنجزت', 'Work done'),
        'cancelled' => _t('أُلغيت', 'Cancelled'),
        _ => s,
      };

  String type(String t) => t == 'inspection' ? _t('فحص', 'Inspection') : _t('إصلاح', 'Repair');

  // T-03 / T-04
  String get problem => _t('المشكلة كما وصفها العميل', 'The problem as the customer described it');
  String get instructions => _t('تعليمات المنسق', 'Coordinator instructions');
  String get intake => _t('بطاقة الاستلام', 'Receiving card');
  String get accessories => _t('الملحقات', 'Accessories');
  String get condition => _t('الحالة عند الاستلام', 'Condition on receipt');
  String get intakeReading => _t('قراءة العداد عند الاستلام', 'Hour meter on receipt');
  String get history => _t('آخر طلبات الصيانة', 'Recent service requests');
  String get scope => _t('نطاق العمل المعتمد', 'Approved scope');
  String get team => _t('الفريق', 'Team');
  String get workedTime => _t('وقت العمل', 'Working time');
  String get start => _t('بدأت العمل', 'Start work');
  String get pause => _t('إيقاف مؤقت', 'Pause');
  String get resume => _t('استئناف', 'Resume');
  String get pauseReason => _t('سبب الإيقاف', 'Pause reason');
  String get noteOptional => _t('ملاحظة (اختياري)', 'Note (optional)');
  String get startNeedsIntake => _t('لا يبدأ العمل قبل تسجيل بطاقة الاستلام (BR-20).', 'Work cannot start before the receiving card is recorded (BR-20).');
  String get startNotToday => _t('موعد هذه المهمة لم يحن بعد.', 'This task is not due yet.');
  String anotherActive(String n) => _t('لديك مهمة جارية ($n). أوقفها أولاً.', 'You have a task in progress ($n). Pause it first.');
  String get finishLater => _t('إنهاء العمل ونتيجة الاختبار في الإصدار القادم.', 'Finishing with the test result comes in the next release.');
  String holdReason(String r) => switch (r) {
        'awaiting_parts' => _t('بانتظار قطع', 'Awaiting parts'),
        'awaiting_approval' => _t('بانتظار موافقة', 'Awaiting approval'),
        'awaiting_customer' => _t('بانتظار العميل', 'Awaiting customer'),
        'end_of_shift' => _t('نهاية الدوام', 'End of shift'),
        'other' => _t('سبب آخر', 'Other reason'),
        _ => r,
      };

  // T-06
  String get photos => _t('الصور', 'Photos');
  String get addPhoto => _t('صورة', 'Photo');
  String get noPhotos => _t('لا صور بعد.', 'No photos yet.');
  String phase(String p) => switch (p) {
        'before' => _t('قبل', 'Before'),
        'during' => _t('أثناء', 'During'),
        'after' => _t('بعد', 'After'),
        'hour_meter' => _t('العداد', 'Hour meter'),
        'damage' => _t('ضرر', 'Damage'),
        'test' => _t('اختبار', 'Test'),
        _ => p,
      };
  String get notes => _t('الملاحظات الداخلية', 'Internal notes');
  String get addNote => _t('ملاحظة', 'Note');
  String get noteHint => _t('لا تظهر للعميل', 'Not shown to the customer');
  String get noNotes => _t('لا ملاحظات بعد.', 'No notes yet.');
  String get fromPrevious => _t('من فني سابق', 'From a previous technician');
  String get hourMeter => _t('عداد الساعات', 'Hour meter');
  String get recordMeter => _t('تسجيل القراءة', 'Record reading');
  String get reading => _t('القراءة', 'Reading');
  String get meterPhoto => _t('صورة العداد', 'Hour meter photo');
  String lastReading(String r) => _t('آخر قراءة: $r', 'Last reading: $r');
  String get lowerReading => _t('القراءة أقل من السابقة، وستُعلَّم للمراجعة.', 'The reading is lower than the previous one and will be flagged for review.');
  String get componentNoMeter => _t('مكوّن منفصل بلا عداد.', 'A separate component has no hour meter.');
  String get pendingMark => _t('بانتظار الإرسال', 'Waiting to send');

  // T-07
  String get parts => _t('القطع المستخدمة', 'Parts used');
  String get addPart => _t('قطعة', 'Part');
  String get noParts => _t('لا قطع بعد.', 'No parts yet.');
  String get fromStore => _t('من المخزن', 'From store');
  String get fromCustomer => _t('من العميل', 'From customer');
  String get purchased => _t('مشتراة', 'Purchased');
  String get searchParts => _t('رقم القطعة أو اسمها', 'Part number or name');
  String available(String q) => _t('المتاح: $q', 'Available: $q');
  String get quantity => _t('الكمية', 'Quantity');
  String get description => _t('الوصف', 'Description');
  String get storeNeedsConnection => _t('البحث في المخزن يحتاج اتصالاً. القطعة من العميل تُسجل دون اتصال.',
      'Searching the store needs a connection. Customer parts can be recorded offline.');
  String get remove => _t('حذف', 'Remove');
  String get removed => _t('محذوفة', 'Removed');
  String source(String s) => switch (s) {
        'store' => fromStore,
        'customer_supplied' => fromCustomer,
        'purchased' => purchased,
        _ => s,
      };

  // T-11
  String get syncCenter => _t('مركز الإرسال', 'Sync center');
  String get sendNow => _t('أرسل الآن', 'Send now');
  String get sending => _t('جارٍ الإرسال...', 'Sending...');
  String get nothingPending => _t('كل التغييرات أُرسلت.', 'All changes have been sent.');
  String get pendingTitle => _t('بانتظار الإرسال', 'Waiting to send');
  String get rejectedTitle => _t('رفضها الخادم', 'Rejected by the server');
  String get rejectedHint => _t('لم تُطبَّق هذه التغييرات. راجع المنسق إن لزم.', 'These changes were not applied. Check with the coordinator if needed.');
  String get dismiss => _t('تجاهل', 'Dismiss');
  String lastSync(String t) => _t('آخر إرسال ناجح: $t', 'Last successful send: $t');
  String eventLabel(String type, Map<String, dynamic> p) => switch (type) {
        'status_changed' => p['to'] == 'in_progress' ? start : '$pause · ${holdReason('${p['reason']}')}',
        'photo_added' => '$addPhoto · ${phase('${p['phase']}')}',
        'note_added' => addNote,
        'part_used' => '$addPart · ${p['label'] ?? p['description'] ?? ''} × ${p['quantity']}',
        'part_removed' => '$remove $addPart',
        'hour_meter_recorded' => '$hourMeter · ${p['reading']}',
        _ => type,
      };

  // T-12
  String get account => _t('حسابي', 'My account');
  String get skills => _t('المهارات', 'Skills');
  String get branch => _t('الفرع', 'Branch');
  String get language => _t('اللغة', 'Language');
  String get logout => _t('تسجيل الخروج', 'Sign out');
  String logoutPending(int n) => _t('لديك $n تغييرات لم تُرسل بعد، وستُحذف من الجهاز عند الخروج. أرسلها أولاً.',
      'You have $n changes not sent yet. Signing out deletes them from this device. Send them first.');
  String get logoutAnyway => _t('خروج وحذفها', 'Sign out and delete');
}
