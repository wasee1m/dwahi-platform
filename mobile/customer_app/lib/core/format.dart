/// عرض المال (BR-01، 06 §4.2): "1,250.500 ر.ع." بثلاث خانات، نصياً بلا أرقام عائمة
String formatMoney(Map<String, dynamic>? money, String locale) {
  if (money == null) return '—';
  final amount = (money['amount'] as String?) ?? '0';
  final parts = amount.split('.');
  final grouped = parts[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  final frac = (parts.length > 1 ? parts[1] : '').padRight(3, '0').substring(0, 3);
  final unit = money['currency'] == 'OMR' || money['currency'] == null ? (locale == 'ar' ? 'ر.ع.' : 'OMR') : money['currency'] as String;
  return locale == 'ar' ? '$grouped.$frac $unit' : '$unit $grouped.$frac';
}

/// مبلغ نصي من الخادم (مثل line_total) بنفس الشكل
String formatAmount(String amount, String locale) => formatMoney({'amount': amount, 'currency': 'OMR'}, locale);

/// الأيام المتبقية حتى نهاية يوم الصلاحية بتوقيت مسقط (UTC+4، BR-02). 0 = آخر يوم.
int daysLeft(String validUntil, {DateTime? now}) {
  final muscat = (now ?? DateTime.now()).toUtc().add(const Duration(hours: 4));
  final today = DateTime.utc(muscat.year, muscat.month, muscat.day);
  return DateTime.parse('${validUntil}T00:00:00Z').difference(today).inDays;
}

/// "+96895534404" ← "+968 9553 4404"
String formatPhone(String e164) {
  final m = RegExp(r'^\+968(\d{4})(\d{4})$').firstMatch(e164);
  return m == null ? e164 : '+968 ${m[1]} ${m[2]}';
}
