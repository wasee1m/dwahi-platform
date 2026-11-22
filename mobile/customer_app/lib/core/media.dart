import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';

/// روابط الصور والملفات من الـ API. في التطوير يعيدها الخادم بـ localhost، والمحاكي يرى جهاز التطوير
/// بعنوان API_URL (10.0.2.2)، فيُستبدل المضيف. في الإنتاج الروابط من الـ CDN ولا تتغير.
String mediaUrl(String url) {
  final api = Uri.parse(apiUrl);
  final u = Uri.parse(url);
  if (u.host == 'localhost' || u.host == '127.0.0.1') return u.replace(host: api.host, port: api.port, scheme: api.scheme).toString();
  return url;
}

/// هامش قائمة تملأ الشاشة: يضيف ارتفاع شريط النظام، فلا يختفي آخر زر تحته
EdgeInsets screenPadding(BuildContext context, [double side = 16]) =>
    EdgeInsets.fromLTRB(side, side, side, side + MediaQuery.paddingOf(context).bottom);

/// صورة من الشبكة بخلفية رملية أثناء التحميل أو عند الفشل
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, this.fit = BoxFit.cover, this.height});
  final String? url;
  final BoxFit fit;
  final double? height;

  @override
  Widget build(BuildContext context) {
    final bg = Container(height: height, color: Theme.of(context).colorScheme.surfaceContainerHighest, child: const Center(child: Icon(Icons.construction_outlined)));
    if (url == null) return bg;
    return Image.network(mediaUrl(url!), fit: fit, height: height, width: double.infinity,
        errorBuilder: (_, _, _) => bg, loadingBuilder: (_, child, p) => p == null ? child : bg);
  }
}

/// واتساب برسالة معبأة (FR-MSG-02)
Future<void> openWhatsApp(String phoneE164, String text) =>
    launchUrl(Uri.parse('https://wa.me/${phoneE164.replaceAll(RegExp(r'\D'), '')}?text=${Uri.encodeComponent(text)}'), mode: LaunchMode.externalApplication);

/// رابط خارجي (PDF موقّع، اتصال) في التطبيق المناسب على الجهاز
Future<void> openExternal(String url) => launchUrl(Uri.parse(mediaUrl(url)), mode: LaunchMode.externalApplication);
