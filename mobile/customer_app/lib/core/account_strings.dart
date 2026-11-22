import 'strings.dart';

/// نصوص الحساب والأسطول (S5: M-13، M-14، M-22)، مفصولة لطول القائمة
extension AccountStrings on S {
  String _a(String ar, String en) => locale.languageCode == 'en' ? en : ar;

  // مشترك
  String get saved => _a('تم الحفظ.', 'Saved.');
  String get confirm => _a('تأكيد', 'Confirm');
  String get edit => _a('تعديل', 'Edit');
  String get add => _a('إضافة', 'Add');
  String get account => _a('الحساب', 'Account');
  String get personal => _a('شخصي', 'Personal');
  String get listSeparator => _a('، ', ', ');

  // M-22
  String get profile => _a('الملف والأمان', 'Profile and security');
  String get companyAndMembers => _a('الشركة والأعضاء', 'Company and members');
  String get addresses => _a('العناوين', 'Addresses');
  String get myFleet => _a('أسطولي', 'My fleet');
  String get invoices => _a('الفواتير', 'Invoices');
  String get notificationPrefs => _a('تفضيلات الإشعارات', 'Notification preferences');
  String get devices => _a('الأجهزة والجلسات', 'Devices and sessions');
  String get privacy => _a('بياناتي وحذف الحساب', 'My data and account deletion');
  String get help => _a('المساعدة', 'Help');
  String get policies => _a('السياسات', 'Policies');
  String appVersion(String v) => _a('إصدار التطبيق $v', 'App version $v');

  // الملف
  String get email => _a('البريد الإلكتروني', 'Email');
  String get emailVerified => _a('بريدك موثق.', 'Your email is confirmed.');
  String get verifyEmail => _a('وثّق بريدك', 'Confirm your email');
  String get verifyEmailWhy => _a('استعادة كلمة المرور تُرسل للبريد الموثق فقط.', 'Password recovery is only sent to a confirmed email.');
  String get sendEmailCode => _a('أرسل رمز التوثيق', 'Send a confirmation code');
  String get changePhone => _a('تغيير رقم الجوال', 'Change mobile number');
  String get newPhone => _a('الرقم الجديد', 'New number');
  String get phoneChanged => _a('تغيّر رقمك، ونبّهنا الرقم القديم.', 'Your number changed. We alerted the old number.');
  String get password => _a('كلمة المرور (اختيارية)', 'Password (optional)');
  String get currentPassword => _a('كلمة المرور الحالية', 'Current password');
  String get newPassword => _a('كلمة المرور الجديدة (10 أحرف فأكثر)', 'New password (10 characters or more)');
  String get confirmPassword => _a('أعد كتابتها', 'Repeat it');
  String get passwordNeedsEmail => _a('أضف بريدك أولاً لتنشئ كلمة مرور.', 'Add your email first to create a password.');

  // الشركة
  String get noCompany => _a('لست عضواً في شركة. أنشئ حساب شركتك أو اطلب من مالكها دعوتك.', 'You are not a member of a company. Create one or ask its owner to invite you.');
  String get newCompany => _a('إنشاء حساب شركة', 'Create a company account');
  String get crNumber => _a('السجل التجاري', 'Commercial registration');
  String get vatNumber => _a('الرقم الضريبي', 'VAT number');
  String get preapproval => _a('حد الموافقة المسبقة على الصيانة (ر.ع.)', 'Maintenance pre-approval limit (OMR)');
  String get preapprovalShort => _a('حد الموافقة المسبقة على الصيانة', 'Maintenance pre-approval limit');
  String get preapprovalHint => _a('تُعتمد تلقائياً التقديرات التي لا تتجاوزه. 0 = وافق على كل تقدير.', 'Estimates up to this are approved automatically. 0 = approve each one.');
  String get members => _a('الأعضاء', 'Members');
  String get you => _a('أنت', 'you');
  String roleName(String r) => r == 'owner' ? _a('مالك', 'Owner') : _a('عضو', 'Member');
  String get role => _a('الدور', 'Role');
  String get canApprove => _a('اعتماد العروض', 'Approves quotes');
  String get invite => _a('دعوة عضو', 'Invite a member');
  String get memberPhone => _a('جوال العضو', 'Member mobile');
  String get invited => _a('أُرسلت الدعوة، وهي صالحة 7 أيام.', 'Invitation sent. It is valid for 7 days.');
  String get pendingInvitations => _a('الدعوات المعلقة', 'Pending invitations');
  String get removeMember => _a('إزالة العضو', 'Remove member');
  String get removeMemberQuestion => _a('يفقد الوصول فوراً، وتبقى طلباته للشركة.', 'They lose access at once. Their requests stay with the company.');
  String get leave => _a('مغادرة الشركة', 'Leave company');
  String get leaveQuestion => _a('ستفقد الوصول إلى طلبات الشركة وأسطولها.', "You will lose access to the company's requests and fleet.");
  String invitedTo(String company) => _a('دعوة للانضمام إلى $company', 'Invitation to join $company');
  String invitedBy(String name) => _a('من $name', 'From $name');
  String get decline => _a('رفض', 'Decline');

  // العناوين
  String get noAddresses => _a('لا عناوين محفوظة في هذا الحساب.', 'No saved addresses in this account.');
  String get addAddress => _a('إضافة عنوان', 'Add address');
  String get addressLabel => _a('اسم العنوان، مثل مستودع الخوض', 'Address name, like Al Khoud warehouse');
  String get addressKind => _a('الاستخدام', 'Used for');
  String addressKindName(String k) => switch (k) {
        'delivery' => _a('توصيل', 'Delivery'),
        'site' => _a('موقع معدات', 'Equipment site'),
        _ => _a('توصيل وموقع معدات', 'Delivery and equipment site'),
      };
  String get wilayat => _a('الولاية', 'Wilayat');
  String get directions => _a('وصف الوصول', 'Directions');
  String get latitude => _a('خط العرض', 'Latitude');
  String get longitude => _a('خط الطول', 'Longitude');
  String get pinHint => _a('الدبوس اختياري: الصق الإحداثيات من تطبيق الخرائط.', 'The pin is optional: paste coordinates from a maps app.');
  String get contactName => _a('شخص التواصل', 'Contact person');
  String get contactPhone => _a('جوال التواصل', 'Contact mobile');
  String get archiveAddressQuestion => _a('لن يتغير في طلباتك السابقة.', 'Past requests keep it unchanged.');
  String get openMap => _a('افتح في الخرائط', 'Open in maps');

  // M-13، M-14
  String get noFleet => _a('لا توجد معدات في أسطولك بعد.', 'There is no equipment in your fleet yet.');
  String get addEquipment => _a('أضف معدة', 'Add equipment');
  String get searchFleet => _a('الاسم أو الموديل أو الرقم التسلسلي', 'Name, model or serial number');
  String get more => _a('المزيد', 'More');
  String get model => _a('الموديل', 'Model');
  String get otherModel => _a('موديل آخر (أكتبه)', 'Another model (type it)');
  String get brandName => _a('اسم الماركة', 'Brand name');
  String get modelName => _a('اسم الموديل', 'Model name');
  String get nickname => _a('اسم مختصر، مثل الحفار رقم 3', 'Short name, like Excavator 3');
  String get serial => _a('الرقم التسلسلي أو رقم الهيكل', 'Serial or chassis number');
  String get hourMeter => _a('عداد الساعات', 'Hour meter');
  String get noReading => _a('لا قراءة للعداد', 'No meter reading');
  String get siteAddress => _a('موقع المعدة', 'Equipment site');
  String get nameplatePhoto => _a('صورة اللوحة التعريفية', 'Nameplate photo');
  String get photoAdded => _a('أُضيفت الصورة', 'Photo added');
  String get updateMeter => _a('تحديث العداد', 'Update meter');
  String get meterPhoto => _a('صورة العداد (اختياري)', 'Meter photo (optional)');
  String lowerReading(Object previous) => _a('القراءة أقل من السابقة ($previous). إن بُدّل العداد فأكد واكتب السبب.', 'The reading is lower than the previous one ($previous). If the meter was replaced, confirm and give the reason.');
  String get lowerReason => _a('سبب القراءة الأقل', 'Reason for the lower reading');
  String get confirmLower => _a('أؤكد القراءة الأقل', 'Confirm the lower reading');
  String get partsForMachine => _a('قطع لهذه المعدة', 'Parts for this machine');
  String get requestPartForMachine => _a('اطلب قطعة لهذه المعدة', 'Request a part for this machine');
  String get notLinked => _a('هذه المعدة غير مرتبطة بموديل في الكتالوج، فلا نستطيع عرض قطعها المتوافقة.', 'This machine is not linked to a catalog model, so we cannot show compatible parts.');
  String get history => _a('السجل', 'History');
  String get noHistory => _a('لا سجل بعد.', 'No history yet.');
  String get needsReview => _a('تحتاج مراجعة', 'Needs review');
  String get removeFromFleet => _a('حذف من الأسطول', 'Remove from fleet');
  String get removeFleetQuestion => _a('تبقى في سجل طلباتك السابقة.', 'It stays on your past requests.');

  // M-09 حسب المعدة
  String get byNumber => _a('بالرقم', 'By number');
  String get byMachine => _a('حسب المعدة', 'By machine');
  String get fromFleet => _a('من أسطولي', 'From my fleet');
  String get noPartsForModel => _a('لا قطع مرتبطة بهذا الموديل بعد.', 'No parts are linked to this model yet.');
  String get otherCategory => _a('أخرى', 'Other');

  // الطلب
  String get relatedEquipment => _a('المعدة المرتبطة', 'Related equipment');
  String get deliveryAddress => _a('عنوان التسليم', 'Delivery address');
  String get none => _a('بدون', 'None');

  // التفضيلات
  String channel(String c) => switch (c) { 'push' => _a('التطبيق', 'App'), 'email' => _a('البريد', 'Email'), _ => 'SMS' };
  String category(String c) => switch (c) {
        'quotes' => _a('العروض', 'Quotes'),
        'orders' => _a('الطلبات', 'Orders'),
        'service' => _a('الصيانة', 'Service'),
        'messages' => _a('الرسائل', 'Messages'),
        _ => _a('العروض التسويقية', 'Marketing'),
      };
  String get mandatory => _a('إلزامي', 'Mandatory');
  String get exceptMandatory => _a('عدا الإلزامي', 'Except mandatory');
  String get mandatoryHint => _a('الإشعارات الإلزامية، مثل وصول عرض سعر، تصلك دائماً.', 'Mandatory notifications, such as a new quote, always reach you.');
  String get marketingMessages => _a('الرسائل التسويقية', 'Marketing messages');
  String get marketingHint => _a('لا تصلك حتى تفعّلها.', 'You get none until you turn them on.');

  // الجلسات
  String client(String c) => c == 'customer_app' ? _a('تطبيق الجوال', 'Mobile app') : _a('الموقع', 'Website');
  String get thisDevice => _a('هذا الجهاز', 'This device');
  String get endSession => _a('إنهاء', 'End');
  String get logoutAll => _a('الخروج من كل الأجهزة', 'Sign out of all devices');
  String get logoutAllQuestion => _a('ستحتاج الدخول من جديد على كل جهاز، وهذا منها.', 'You will need to sign in again on every device, including this one.');

  // الخصوصية
  String get exportTitle => _a('تنزيل بياناتي', 'Download my data');
  String get exportIntro => _a('ملف JSON ببياناتك، يجهز خلال 24 ساعة كحد أقصى ويصلك إشعار.', 'A JSON file of your data, ready within 24 hours at most. We notify you.');
  String get exportRequest => _a('جهّز نسخة من بياناتي', 'Prepare a copy of my data');
  String get exportPending => _a('نجهز ملفك. سيصلك إشعار عند جاهزيته.', 'We are preparing your file and will notify you.');
  String get exportFailed => _a('تعذّر تجهيز الملف. اطلبه مرة أخرى.', 'The file could not be prepared. Ask again.');
  String get exportDownload => _a('تنزيل الملف', 'Download the file');
  String get deleteAccount => _a('حذف الحساب', 'Delete account');
  String deleteIntro(int days) => _a('يُعطَّل الحساب فوراً، وتُجهَّل بياناتك الشخصية بعد $days يوماً. تُحفظ السجلات المالية للمدة النظامية.',
      'The account is disabled at once and your personal data is anonymized after $days days. Financial records are kept for the legal period.');
  String get transferFirst => _a('انقل ملكية هذه الشركات أولاً:', 'Transfer ownership of these companies first:');
  String get closeFirst => _a('ألغِ هذه الطلبات أو انتظر إغلاقها:', 'Cancel these requests or wait until they close:');
  String get sendDeleteCode => _a('أرسل رمز تأكيد الحذف', 'Send a deletion code');
  String get deleteNow => _a('احذف حسابي', 'Delete my account');
  String get accountDeleted => _a('حُذف حسابك.', 'Your account was deleted.');

  // المساعدة
  String get faq => _a('الأسئلة الشائعة', 'Frequently asked questions');
  String get branches => _a('الفروع', 'Branches');
  String get call => _a('اتصال', 'Call');
  String policy(String slug) => switch (slug) {
        'terms' => _a('الشروط والأحكام', 'Terms and conditions'),
        'privacy' => _a('سياسة الخصوصية', 'Privacy policy'),
        'returns' => _a('سياسة الإرجاع', 'Returns policy'),
        _ => _a('سياسة الضمان', 'Warranty policy'),
      };
}
