import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/theme.dart';
import 'widgets/logo.dart';

void main() => runApp(const ProviderScope(child: TechnicianApp()));

/// تطبيق الفني (07 §2): هيكل S0 فقط. الدخول بربط الجهاز (T-01) والمهام وتحمّل الانقطاع في S8 (09 §7).
/// الـ API جاهز: POST /auth/technician/login برمز تفعيل أو بجهاز مسجل (FR-AUTH-03).
class TechnicianApp extends StatelessWidget {
  const TechnicianApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'DWAHI-ALBADIA Workshop',
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: dwTheme(Brightness.light),
      darkTheme: dwTheme(Brightness.dark),
      home: const Scaffold(
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DwLogo(size: 120),
              SizedBox(height: 24),
              Text('تطبيق الفني', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            ],
          ),
        ),
      ),
    );
  }
}
