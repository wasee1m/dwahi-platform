import 'strings.dart';

/// نصوص الصيانة (S7: M-15، M-16)
extension ServiceStrings on S {
  String _v(String ar, String en) => locale.languageCode == 'en' ? en : ar;

  String get serviceRequests => _v('طلبات الصيانة', 'Service requests');
  String get newServiceRequest => _v('طلب صيانة جديد', 'New service request');
  String get noServiceRequests => _v('لا طلبات صيانة هنا.', 'No service requests here.');
  String serviceTab(String t) => switch (t) {
        'active' => _v('النشطة', 'Active'),
        'awaiting' => _v('بانتظاري', 'Awaiting me'),
        'done' => _v('المكتملة', 'Done'),
        _ => _v('الملغاة', 'Cancelled'),
      };
  String customerStatus(String key, {String? date, String? reason}) => switch (key) {
        'submitted' => _v('تم استلام طلبك', 'Request received'),
        'triaged' => _v('قيد المراجعة', 'Under review'),
        'awaiting_approval' => _v('بانتظار موافقتك على التكلفة', 'Awaiting your approval'),
        'approved' => _v('تمت الموافقة، جارٍ الجدولة', 'Approved, scheduling'),
        'awaiting_dropoff' => _v('بانتظار إحضار المعدة إلى الورشة', 'Awaiting drop-off at workshop'),
        'scheduling' => _v('جارٍ تحديد الموعد', 'Scheduling'),
        'scheduled' => _v('موعد العمل: ${date ?? ''}', 'Work scheduled: ${date ?? ''}'),
        'in_progress' => _v('جارٍ العمل', 'Work in progress'),
        'on_hold' => _v('متوقف مؤقتاً: ${holdReason(reason)}', 'On hold: ${holdReason(reason)}'),
        'additional_awaiting' => _v('بانتظار موافقتك على عمل إضافي', 'Additional work awaiting your approval'),
        'final_review' => _v('اكتمل العمل، قيد المراجعة النهائية', 'Work done, final review'),
        'completed' => _v('جاهز للاستلام من الورشة', 'Ready for collection'),
        'closed' => _v('مغلق', 'Closed'),
        'cancelled' => _v('ملغى', 'Cancelled'),
        'declined' => _v('تعذّر قبول الطلب: ${reason ?? ''}', 'Declined: ${reason ?? ''}'),
        _ => _v('في الورشة', 'In the workshop'),
      };
  String holdReason(String? r) => switch (r) {
        'awaiting_parts' => _v('بانتظار قطع', 'awaiting parts'),
        'awaiting_approval' => _v('بانتظار موافقة', 'awaiting approval'),
        'awaiting_customer' => _v('بانتظار العميل', 'awaiting the customer'),
        'end_of_shift' => _v('نهاية الدوام', 'end of shift'),
        _ => _v('سبب آخر', 'other reason'),
      };
  String component(String? c) => switch (c) {
        'diesel_engine' => _v('محرك ديزل', 'Diesel engine'),
        'hydraulic_pump' => _v('مضخة هيدروليك', 'Hydraulic pump'),
        'hydraulic_cylinder' => _v('سلندر هيدروليك', 'Hydraulic cylinder'),
        'transmission' => _v('ناقل حركة', 'Transmission'),
        _ => _v('مكوّن آخر', 'Other component'),
      };
  String get stepMachine => _v('المعدة', 'Machine');
  String get stepProblem => _v('العطل', 'Problem');
  String get stepTiming => _v('الموعد', 'Timing');
  String get stepReview => _v('المراجعة', 'Review');
  String get fromMyFleet => _v('معدة من أسطولي', 'A machine from my fleet');
  String get otherMachine => _v('معدة أخرى', 'Another machine');
  String get separateComponent => _v('مكوّن منفصل', 'A separate component');
  String get brandLabel => _v('الماركة', 'Brand');
  String get modelLabel => _v('الموديل', 'Model');
  String get serialOptional => _v('الرقم التسلسلي (اختياري)', 'Serial number (optional)');
  String get componentTypeLabel => _v('نوع المكوّن', 'Component type');
  String get componentDetailsLabel => _v('تفاصيل المكوّن (الماركة، الرقم)', 'Component details (brand, number)');
  String get problemLabel => _v('وصف العطل', 'Problem');
  String get addPhotoOrVideo => _v('أضف صورة أو فيديو', 'Add a photo or video');
  String get recordVideo => _v('صوّر فيديو', 'Record a video');
  String get attachmentsCount => _v('المرفقات', 'Attachments');
  String get urgencyLabel => _v('الاستعجال', 'Urgency');
  String urgencyName(String u) => u == 'urgent' ? _v('عاجل', 'Urgent') : _v('عادي', 'Normal');
  String replyWithin(String u, int n) =>
      u == 'urgent' ? _v('نرد خلال $n ساعة على مدار الساعة.', 'We reply within $n hour, around the clock.') : _v('نرد خلال $n ساعات عمل.', 'We reply within $n working hours.');
  String get preferredDropoff => _v('الموعد المفضل لإحضارها للورشة', 'Preferred drop-off date');
  String get chooseDate => _v('اختر تاريخاً', 'Choose a date');
  String get workshopLabel => _v('الورشة', 'Workshop');
  String inspectionFee(String fee) => _v('رسوم الفحص: $fee', 'Inspection fee: $fee');
  String get noInspectionFee => _v('لا رسوم فحص معلنة.', 'No inspection fee announced.');
  String get feeRule => _v('الإلغاء مجاني قبل بدء الفحص، وبرسوم الفحص إن اكتمل.', 'Cancelling is free before the inspection starts, and costs the fee once it is done.');
  String get acknowledgeFees => _v('اطلعت على رسوم الفحص وسياسة الإلغاء وأوافق عليها', 'I accept the inspection fee and cancellation policy');
  String get sendRequest => _v('أرسل الطلب', 'Send request');
  String get requestSent => _v('استلمنا طلبك وسنراجعه.', 'We received your request.');
  String get nextStep => _v('التالي', 'Next');
  String get prevStep => _v('رجوع', 'Back');
  String get reviewEstimate => _v('راجع التقدير', 'Review the estimate');
  String get receivedWith => _v('استُلمت ببطاقة', 'Received with card');
  String get receivingReceipt => _v('إيصال الاستلام', 'Receiving receipt');
  String get notReceivedYet => _v('لم تُستلم في الورشة بعد.', 'Not received at the workshop yet.');
  String get cancelRequest => _v('إلغاء الطلب', 'Cancel request');
  String get cancelRequestQ => _v('إلغاء طلب الصيانة؟', 'Cancel this service request?');
  String get requestCancelled => _v('أُلغي الطلب.', 'The request was cancelled.');
  String get uploadingFiles => _v('جارٍ الرفع...', 'Uploading...');
}
