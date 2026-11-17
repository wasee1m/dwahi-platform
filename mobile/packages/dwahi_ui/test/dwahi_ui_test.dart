import 'package:dwahi_ui/dwahi_ui.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('theme follows the brand tokens (01 §9.1)', () {
    final light = dwTheme(Brightness.light);
    expect(light.colorScheme.primary, const Color(0xFFEC9915));
    expect(light.colorScheme.onPrimary, const Color(0xFF231F1A)); // لا أبيض على الكهرماني
    expect(dwTheme(Brightness.dark).colorScheme.primary, const Color(0xFFF2A93B));
  });

  testWidgets('logo renders at the requested size', (tester) async {
    await tester.pumpWidget(const Directionality(textDirection: TextDirection.rtl, child: Center(child: DwLogo(size: 80))));
    expect(tester.getSize(find.byType(DwLogo)), const Size(80, 80));
  });
}
