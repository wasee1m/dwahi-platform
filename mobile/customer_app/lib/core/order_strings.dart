import 'strings.dart';

/// نصوص السلة والطلبات والفواتير (S6: M-11، M-19، M-23)
extension OrderStrings on S {
  String _o(String ar, String en) => locale.languageCode == 'en' ? en : ar;

  // M-11 السلة وإتمام الطلب
  String get cart => _o('السلة', 'Cart');
  String get addToCart => _o('أضف للسلة', 'Add to cart');
  String get addedToCart => _o('أُضيفت للسلة.', 'Added to cart.');
  String get viewCart => _o('عرض السلة', 'View cart');
  String get cartEmpty => _o('سلتك فارغة. ابحث عن قطعة بالرقم أو بالمعدة.', 'Your cart is empty. Find a part by number or by machine.');
  String get findPart => _o('ابحث عن قطعة', 'Find a part');
  String get remove => _o('حذف', 'Remove');
  String get subtotal => _o('المجموع قبل الضريبة', 'Subtotal before VAT');
  String get vat => _o('ضريبة القيمة المضافة', 'VAT');
  String get total => _o('الإجمالي', 'Total');
  String get deliveryFee => _o('رسوم التوصيل', 'Delivery fee');
  String get checkout => _o('إتمام الطلب', 'Check out');
  String priceChanged(String from, String to) => _o('تغيّر السعر من $from إلى $to', 'Price changed from $from to $to');
  String get cartChanged => _o('تغيّر سعر أو توفر بعض القطع منذ أضفتها. راجعها قبل المتابعة.', 'Some prices or stock changed since you added them. Review them first.');
  String get acceptPrices => _o('موافق على الأسعار الجديدة', 'Accept the new prices');
  String cartProblem(String p) => switch (p) {
        'inactive' => _o('لم تعد متاحة للطلب. احذفها.', 'No longer available. Remove it.'),
        _ => _o('الكمية أكبر من المتاح الآن.', 'More than we have now.'),
      };
  String get payOnPickupNote => _o('تُحجز القطعة عند تأكيد الطلب، والدفع عند الاستلام أو التسليم.', 'The part is reserved when you order; pay on pickup or delivery.');
  String get pickup => _o('استلام من فرع', 'Pick up from a branch');
  String get delivery => _o('توصيل لعنوان', 'Deliver to an address');
  String get branch => _o('الفرع', 'Branch');
  String get notEnoughHere => _o('لا يملك كل الكميات الآن', 'does not have all quantities now');
  String get noDeliveryAddresses => _o('لا توجد عناوين توصيل في هذا الحساب. أضف عنواناً أولاً.', 'No delivery addresses in this account. Add one first.');
  String get noDelivery => _o('لا توصيل', 'no delivery');
  String get payOnFulfillment => _o('الدفع عند الاستلام أو التسليم', 'Pay on pickup or delivery');
  String get payOnFulfillmentText => _o('نقداً، أو بالبطاقة عبر جهاز نقاط البيع، أو بتحويل بنكي مؤكد. الفاتورة تصدر عند التسليم.',
      'In cash, by card on the POS machine, or by confirmed bank transfer. The invoice is issued on handover.');
  String get willCall => _o('سنتصل بك لتأكيد الطلب قبل التجهيز.', 'We will call you to confirm before preparing.');
  String openLimit(int n) => _o('لديك $n طلبات مفتوحة غير مدفوعة، وهو الحد الأقصى. استلم طلباتك الحالية أولاً.',
      'You have $n open unpaid orders, which is the limit. Collect your current orders first.');
  String get notesForBranch => _o('ملاحظات للفرع (اختياري)', 'Notes for the branch (optional)');
  String get acceptReturns => _o('قرأت سياسة الإرجاع وأوافق عليها', 'I read and accept the returns policy');
  String get placeOrder => _o('تأكيد الطلب', 'Place order');
  String checkoutProblem(String p) => switch (p) {
        'branch_required' => _o('اختر فرع الاستلام.', 'Choose the pickup branch.'),
        'branch_cannot_fulfill' => _o('هذا الفرع لا يملك كل الكميات الآن. اختر غيره أو التوصيل.', 'This branch does not have all quantities. Choose another or delivery.'),
        'address_required' => _o('اختر عنوان التوصيل.', 'Choose the delivery address.'),
        'no_delivery_to_governorate' => _o('لا نوصّل لهذه المحافظة حالياً. اختر الاستلام.', 'We do not deliver to this governorate yet. Choose pickup.'),
        'no_branch_can_fulfill' => _o('لا يوجد فرع يملك كل الكميات الآن.', 'No branch has all the quantities now.'),
        _ => _o('اختر التوصيل أو الاستلام.', 'Choose delivery or pickup.'),
      };

  // M-16 تبويب القطع، و M-19
  String get noOrders => _o('لا طلبات هنا.', 'No orders here.');
  String orderTab(String t) => switch (t) {
        'open' => _o('المفتوحة', 'Open'),
        'delivered' => _o('المسلّمة', 'Delivered'),
        _ => _o('الملغاة', 'Cancelled'),
      };
  String orderStatus(String st) => switch (st) {
        'confirmed' => _o('مؤكد', 'Confirmed'),
        'processing' => _o('قيد التجهيز', 'Being prepared'),
        'ready_for_pickup' => _o('جاهز للاستلام', 'Ready for pickup'),
        'out_for_delivery' => _o('خرج للتوصيل', 'Out for delivery'),
        'delivered' => _o('تم التسليم', 'Delivered'),
        'cancelled' => _o('ملغى', 'Cancelled'),
        'phone_confirmed' => _o('تأكيد هاتفي', 'Confirmed by phone'),
        'delivery_failed' => _o('تعذّر التسليم', 'Delivery failed'),
        _ => _o('بانتظار الدفع', 'Awaiting payment'),
      };
  String fulfillment(String f) => f == 'pickup' ? _o('استلام من الفرع', 'Branch pickup') : _o('توصيل', 'Delivery');
  String get pickupCode => _o('رمز الاستلام', 'Pickup code');
  String get pickupCodeHint => _o('اعرضه لموظف الفرع عند الاستلام.', 'Show it to the branch staff when you collect.');
  String collectBefore(String when) => _o('استلمه قبل $when', 'Collect before $when');
  String get dueOnPickup => _o('المستحق عند الاستلام', 'Due on pickup');
  String get paid => _o('المدفوع', 'Paid');
  String get items => _o('البنود', 'Items');
  String get timeline => _o('سير الطلب', 'Order progress');
  String get invoice => _o('الفاتورة', 'Invoice');
  String get openPdf => _o('فتح PDF', 'Open PDF');
  String get cancelOrder => _o('إلغاء الطلب', 'Cancel order');
  String get cancelOrderQ => _o('إلغاء هذا الطلب وتحرير القطع؟', 'Cancel this order and release the parts?');
  String get orderCancelled => _o('أُلغي الطلب.', 'The order was cancelled.');
  String get preparingContact => _o('بدأ تجهيز الطلب، فإلغاؤه من الفرع. تواصل معنا.', 'The order is being prepared; the branch handles cancellation.');
  String get requestReturn => _o('طلب إرجاع', 'Request a return');
  String returnUntil(String d) => _o('حتى $d للقطع غير المستخدمة بتغليفها الأصلي', 'Until $d for unused parts in original packaging');
  String get returnReason => _o('سبب الإرجاع', 'Reason for the return');
  String get addPhoto => _o('أضف صورة', 'Add a photo');
  String get sendReturn => _o('أرسل طلب الإرجاع', 'Send return request');
  String get returnSent => _o('استلمنا طلب الإرجاع وسنرد عليك.', 'We received your return request.');
  String get returns => _o('طلبات الإرجاع', 'Return requests');
  String returnStatus(String st) => switch (st) {
        'requested' => _o('بانتظار الرد', 'Awaiting reply'),
        'approved' => _o('مقبول، أحضر القطعة', 'Approved, bring the part'),
        'rejected' => _o('مرفوض', 'Declined'),
        'received' => _o('استُلمت القطعة', 'Part received'),
        _ => _o('تم الاسترداد', 'Refunded'),
      };
  String get notReturnableShort => _o('لا تُرجع', 'Not returnable');
  String get placed => _o('استلمنا طلبك وحجزنا القطع.', 'We received your order and reserved the parts.');

  // M-23 الفواتير
  String get noInvoices => _o('لا فواتير بعد.', 'No invoices yet.');
  String invoiceStatus(String st) => switch (st) {
        'issued' => _o('صادرة', 'Issued'),
        'partially_paid' => _o('مدفوعة جزئياً', 'Partly paid'),
        'paid' => _o('مسددة', 'Paid'),
        'void' => _o('ملغاة', 'Void'),
        _ => _o('مسودة', 'Draft'),
      };
  String get overdue => _o('متأخرة', 'Overdue');
  String invoiceSource(String t) => switch (t) {
        'sales_order' => _o('طلب قطع', 'Parts order'),
        'quotation' => _o('عرض سعر', 'Quotation'),
        _ => _o('صيانة', 'Service'),
      };
  String dueOn(String d) => _o('الاستحقاق $d', 'Due $d');
  String get creditNote => _o('إشعار دائن', 'Credit note');
}
