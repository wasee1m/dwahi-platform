import 'package:customer_app/core/api.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('remembers the chosen language after a restart (M-22)', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final first = ProviderContainer();
    first.read(localeCodeProvider.notifier).set('en');
    await tester.pump();
    first.dispose();

    final restarted = ProviderContainer();
    addTearDown(restarted.dispose);
    restarted.read(localeCodeProvider);
    await tester.pump();
    expect(restarted.read(localeCodeProvider), 'en');
  });
}
