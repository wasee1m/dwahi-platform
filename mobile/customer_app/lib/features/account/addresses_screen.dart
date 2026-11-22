import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../catalog/catalog_screens.dart';
import 'account_common.dart';

// M-22 العناوين (C-04، FR-ACC-04، US-08). الحذف أرشفة (BR-17).

class AddressesScreen extends ConsumerWidget {
  const AddressesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final account = ref.watch(selectedAccountProvider);
    final async = ref.watch(addressesProvider);

    Future<void> form([Map<String, dynamic>? address]) async {
      final ok = await Navigator.of(context).push<bool>(
          MaterialPageRoute(builder: (_) => AddressFormScreen(address: address, organizationId: organizationOf(account))));
      if (ok == true) ref.invalidate(addressesProvider);
    }

    return Scaffold(
      appBar: AppBar(title: Text(s.addresses)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(addressesProvider)),
        data: (all) {
          final rows = all.where((a) => accountOf(a) == account).toList();
          return ListView(padding: screenPadding(context), children: [
            const AccountPicker(),
            if (rows.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 24), child: Text(s.noAddresses)),
            for (final a in rows)
              Card(child: ListTile(
                title: Text('${a['label']}'),
                subtitle: Text([
                  '${s.addressKindName(a['kind'] as String)} · ${s.governorateName(a['governorate'] as String)} · ${a['wilayat']}',
                  if ((a['description'] as String?)?.isNotEmpty == true) a['description'] as String,
                  if (a['contact_phone'] != null) '${a['contact_name'] ?? ''} ${ltr(formatPhone(a['contact_phone'] as String))}'.trim(),
                ].join('\n')),
                onTap: () => form(a),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (a['latitude'] != null && a['longitude'] != null)
                    IconButton(
                      tooltip: s.openMap,
                      icon: const Icon(Icons.map_outlined),
                      onPressed: () => openExternal('https://www.google.com/maps?q=${a['latitude']},${a['longitude']}'),
                    ),
                  IconButton(
                    tooltip: s.remove,
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () async {
                      if (!await askConfirm(context, s.archiveAddressQuestion) || !context.mounted) return;
                      await runAction(context, () async {
                        await ref.read(apiProvider).delete('/me/addresses/${a['id']}');
                        ref.invalidate(addressesProvider);
                      });
                    },
                  ),
                ]),
              )),
            const SizedBox(height: 12),
            OutlinedButton.icon(onPressed: () => form(), icon: const Icon(Icons.add), label: Text(s.addAddress)),
          ]);
        },
      ),
    );
  }
}

class AddressFormScreen extends ConsumerStatefulWidget {
  const AddressFormScreen({super.key, this.address, this.organizationId});
  final Map<String, dynamic>? address;
  final String? organizationId;

  @override
  ConsumerState<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends ConsumerState<AddressFormScreen> {
  late final a = widget.address;
  late final label = TextEditingController(text: a?['label'] as String?);
  late final wilayat = TextEditingController(text: a?['wilayat'] as String?);
  late final description = TextEditingController(text: a?['description'] as String?);
  late final latitude = TextEditingController(text: a?['latitude']?.toString());
  late final longitude = TextEditingController(text: a?['longitude']?.toString());
  late final contactName = TextEditingController(text: a?['contact_name'] as String?);
  late final contactPhone = TextEditingController(text: a?['contact_phone'] as String?);
  late String kind = a?['kind'] as String? ?? 'delivery';
  late String? governorate = a?['governorate'] as String?;
  Map<String, String> errors = {};
  bool busy = false;

  @override
  void dispose() {
    for (final c in [label, wilayat, description, latitude, longitude, contactName, contactPhone]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    setState(() {
      busy = true;
      errors = {};
    });
    String? value(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    final body = {
      'label': label.text.trim(), 'kind': kind, 'governorate': governorate, 'wilayat': wilayat.text.trim(),
      'description': value(description), 'latitude': value(latitude), 'longitude': value(longitude),
      'contact_name': value(contactName), 'contact_phone': value(contactPhone),
    };
    final api = ref.read(apiProvider);
    try {
      if (a == null) {
        await api.post('/me/addresses', {...body, 'organization_id': widget.organizationId});
      } else {
        await api.patch('/me/addresses/${a!['id']}', body);
      }
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => errors = fieldErrors(e));
        toast(context, failureText(e, S.of(context)));
      }
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    const decimal = TextInputType.numberWithOptions(decimal: true, signed: true);
    return Scaffold(
      appBar: AppBar(title: Text(a == null ? s.addAddress : s.edit)),
      body: ListView(padding: screenPadding(context), children: [
        Input(label, s.addressLabel, error: errors['label']),
        Choice(
          label: s.addressKind,
          value: kind,
          options: {for (final k in ['delivery', 'site', 'both']) k: s.addressKindName(k)},
          onChanged: (v) => setState(() => kind = v!),
        ),
        Choice(
          label: s.governorate,
          value: governorate,
          error: errors['governorate'],
          options: {for (final g in S.governorates.keys) g: s.governorateName(g)},
          onChanged: (v) => setState(() => governorate = v),
        ),
        Input(wilayat, s.wilayat, error: errors['wilayat']),
        Input(description, s.directions, error: errors['description'], lines: 2),
        Text(s.pinHint, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 8),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Input(latitude, s.latitude, error: errors['latitude'], latin: true, keyboard: decimal)),
          const SizedBox(width: 8),
          Expanded(child: Input(longitude, s.longitude, error: errors['longitude'], latin: true, keyboard: decimal)),
        ]),
        Input(contactName, s.contactName, error: errors['contact_name']),
        Input(contactPhone, s.contactPhone, error: errors['contact_phone'], latin: true, keyboard: TextInputType.phone),
        FilledButton(onPressed: busy ? null : save, child: Text(s.save)),
      ]),
    );
  }
}
