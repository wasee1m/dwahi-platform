import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:technician_app/main.dart';

void main() {
  testWidgets('opens in Arabic, right to left', (tester) async {
    await tester.pumpWidget(const ProviderScope(child: TechnicianApp()));
    expect(find.text('تطبيق الفني'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('تطبيق الفني'))), TextDirection.rtl);
  });
}
