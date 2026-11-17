import 'package:flutter/material.dart';

import 'theme.dart';

/// شعار "الضاد" (brand/dwahi-mark.svg) مرسوماً بالكود، بلا اعتماد على مكتبة SVG
class DwLogo extends StatelessWidget {
  const DwLogo({super.key, this.size = 48, this.inverse = false});
  final double size;
  final bool inverse;

  @override
  Widget build(BuildContext context) => CustomPaint(size: Size.square(size), painter: _MarkPainter(inverse ? DwColors.onNight : DwColors.ink));
}

class _MarkPainter extends CustomPainter {
  _MarkPainter(this.ink);
  final Color ink;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.scale(size.width / 200);
    final stroke = Paint()
      ..color = ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 24
      ..strokeJoin = StrokeJoin.round;
    canvas.drawRRect(RRect.fromRectAndRadius(const Rect.fromLTWH(100, 76, 68, 46), const Radius.circular(23)), stroke);
    canvas.drawPath(Path()..moveTo(123, 122)..lineTo(94, 122)..cubicTo(94, 180, 32, 180, 32, 118), stroke);
    final fill = Paint()..color = ink;
    for (final x in [20.0, 28.0, 36.0]) {
      canvas.drawPath(Path()..moveTo(x, 119)..lineTo(x + 4, 106)..lineTo(x + 8, 119)..close(), fill);
    }
    canvas.drawCircle(const Offset(134, 40), 17, Paint()..color = DwColors.sun);
  }

  @override
  bool shouldRepaint(_MarkPainter old) => old.ink != ink;
}
