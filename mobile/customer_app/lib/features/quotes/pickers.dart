import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/strings.dart';

/// ملف محلي مختار: مسار واسم
typedef PickedFile = ({String path, String name});

/// صورة من الكاميرا أو المعرض، أو ملف PDF (لأمر الشراء). الصلاحية تُطلب عند أول استخدام (07 §1.3).
/// الصورة تُصغَّر على الجهاز قبل الرفع، والخادم يعيد ترميزها ويزيل بياناتها الوصفية (NFR-11).
Future<PickedFile?> pickAttachment(BuildContext context, {bool allowPdf = false}) async {
  final s = S.of(context);
  final choice = await showModalBottomSheet<String>(
    context: context,
    builder: (ctx) => SafeArea(child: Column(mainAxisSize: MainAxisSize.min, children: [
      ListTile(leading: const Icon(Icons.photo_camera_outlined), title: Text(s.takePhoto), onTap: () => Navigator.pop(ctx, 'camera')),
      ListTile(leading: const Icon(Icons.photo_library_outlined), title: Text(s.fromGallery), onTap: () => Navigator.pop(ctx, 'gallery')),
      if (allowPdf) ListTile(leading: const Icon(Icons.description_outlined), title: Text(s.fromFiles), onTap: () => Navigator.pop(ctx, 'files')),
    ])),
  );
  if (choice == null) return null;
  if (choice == 'files') {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png']);
    final f = files.isEmpty ? null : files.first;
    return f?.path == null ? null : (path: f!.path!, name: f.name);
  }
  final x = await ImagePicker().pickImage(source: choice == 'camera' ? ImageSource.camera : ImageSource.gallery, maxWidth: 2400, maxHeight: 2400, imageQuality: 85);
  return x == null ? null : (path: x.path, name: x.name);
}
