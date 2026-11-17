import 'package:flutter/material.dart';

/// رموز التصميم من 01_srs.md §9.1 (هوية "الضاد"). الوضع الداكن للتطبيقين في P1.
class DwColors {
  static const sun = Color(0xFFEC9915);
  static const sunDeep = Color(0xFFD57E10);
  static const onSun = Color(0xFF231F1A);
  static const ink = Color(0xFF231F1A);
  static const inkMuted = Color(0xFF6B6256);
  static const sand100 = Color(0xFFF7F3EC);
  static const sand0 = Color(0xFFFFFFFF);
  static const sand200 = Color(0xFFE9E2D3);
  static const sand300 = Color(0xFFD2CBBA);
  static const night = Color(0xFF2B386B);
  static const onNight = Color(0xFFF6F1E7);
  static const danger = Color(0xFFB3261E);
  static const success = Color(0xFF1E7A3A);

  // الليل
  static const sunDark = Color(0xFFF2A93B);
  static const inkDark = Color(0xFFF3EDE2);
  static const inkMutedDark = Color(0xFFB8AE9F);
  static const sand100Dark = Color(0xFF16130F);
  static const sand0Dark = Color(0xFF211D18);
  static const sand200Dark = Color(0xFF2C2721);
  static const sand300Dark = Color(0xFF3D372E);
}

ThemeData dwTheme(Brightness brightness) {
  final dark = brightness == Brightness.dark;
  final scheme = ColorScheme(
    brightness: brightness,
    primary: dark ? DwColors.sunDark : DwColors.sun,
    onPrimary: DwColors.onSun, // لا أبيض على الكهرماني (7.1:1)
    secondary: DwColors.night,
    onSecondary: DwColors.onNight,
    error: DwColors.danger,
    onError: Colors.white,
    surface: dark ? DwColors.sand0Dark : DwColors.sand0,
    onSurface: dark ? DwColors.inkDark : DwColors.ink,
    surfaceContainerHighest: dark ? DwColors.sand200Dark : DwColors.sand200,
    outline: dark ? DwColors.sand300Dark : DwColors.sand300,
    onSurfaceVariant: dark ? DwColors.inkMutedDark : DwColors.inkMuted,
  );
  final radius = BorderRadius.circular(3); // radius-sm: زوايا شبه مستقيمة

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: dark ? DwColors.sand100Dark : DwColors.sand100,
    // الخطوط: IBM Plex Sans Arabic للنصوص و Alexandria للعناوين، تُضاف كملفات أصول مع اعتماد الهوية (Q-01)
    textTheme: Typography.material2021(platform: TargetPlatform.android).black.apply(
          bodyColor: scheme.onSurface,
          displayColor: scheme.onSurface,
        ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: dark ? DwColors.sand200Dark : DwColors.sand100,
      border: OutlineInputBorder(borderRadius: radius, borderSide: BorderSide(color: scheme.outline)),
      enabledBorder: OutlineInputBorder(borderRadius: radius, borderSide: BorderSide(color: scheme.outline)),
      focusedBorder: OutlineInputBorder(borderRadius: radius, borderSide: const BorderSide(color: DwColors.night, width: 2)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(52), // هدف لمس كبير (NFR-15)
        shape: RoundedRectangleBorder(borderRadius: radius),
        textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
      ),
    ),
  );
}
