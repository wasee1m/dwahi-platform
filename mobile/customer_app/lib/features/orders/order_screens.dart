import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/order_strings.dart';
import '../../core/strings.dart';
import '../account/account_common.dart';
import '../catalog/catalog_screens.dart';
import '../quotes/pickers.dart';

// S6: السلة وإتمام الطلب (M-11)، وتبويب طلبات القطع (M-16)، وتفاصيل الطلب (M-19)، والفواتير (M-23).
// الخادم هو الحَكَم في السعر والتوفر والانتقالات، والتطبيق يعرض ويرسل فقط.

final cartProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/me/cart'));

typedef CheckoutChoice = ({String fulfillment, String? branch, String? address, String account});

final checkoutProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, CheckoutChoice>((ref, c) => ref.read(apiProvider).get('/me/checkout', query: {
      'fulfillment': c.fulfillment,
      'branch_id': ?c.branch,
      'address_id': ?c.address,
      'organization_id': organizationOf(c.account) ?? '',
    }));

final orderListProvider = FutureProvider.autoDispose.family<ApiPage, String>((ref, tab) => ref.read(apiProvider).page('/me/orders', query: {'tab': tab}));
final orderProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, id) => ref.read(apiProvider).get('/me/orders/$id'));
final invoicesProvider = FutureProvider.autoDispose<ApiPage>((ref) => ref.read(apiProvider).page('/me/invoices'));

String _lang(BuildContext c) => Localizations.localeOf(c).languageCode;
Map<String, dynamic>? _money(Object? v) => v as Map<String, dynamic>?;

const _statusColors = {
  'confirmed': Color(0xFF1A73E8), 'processing': Color(0xFF7C3AED), 'ready_for_pickup': Color(0xFFB26A00),
  'out_for_delivery': Color(0xFF7C3AED), 'delivered': Color(0xFF1E8E3E), 'cancelled': Color(0xFF374151),
};

/// "أضف للسلة" في M-10. الزائر يدخل أولاً.
Future<void> addToCart(BuildContext context, WidgetRef ref, String partId, int qty) async {
  final s = S.of(context);
  final ok = await runAction(context, () => ref.read(apiProvider).put('/me/cart/items/$partId', {'quantity': qty}));
  if (!ok || !context.mounted) return;
  ref.invalidate(cartProvider);
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(s.addedToCart),
    action: SnackBarAction(label: s.viewCart, onPressed: () => context.push('/cart')),
  ));
}

/// M-11 (1): السلة بتنبيهات السعر والتوفر
class CartScreen extends ConsumerWidget {
  const CartScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(cartProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.cart)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(cartProvider)),
        data: (cart) {
          final items = (cart['items'] as List).cast<Map<String, dynamic>>();
          if (items.isEmpty) {
            return Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(s.cartEmpty, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton(onPressed: () => context.go('/parts'), child: Text(s.findPart)),
            ])));
          }
          final totals = cart['totals'] as Map<String, dynamic>;
          final changed = cart['has_changes'] == true;
          return ListView(padding: screenPadding(context), children: [
            if (changed)
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.cartChanged),
                if (items.any((i) => i['previous_price'] != null))
                  TextButton(
                    onPressed: () async {
                      if (await runAction(context, () => ref.read(apiProvider).post('/me/cart/acknowledge', {}))) ref.invalidate(cartProvider);
                    },
                    child: Text(s.acceptPrices),
                  ),
              ]))),
            for (final i in items) _CartLine(item: i),
            const Divider(),
            _TotalRow(s.subtotal, formatMoney(_money(totals['subtotal']), lang)),
            _TotalRow(s.vat, formatMoney(_money(totals['vat_total']), lang)),
            _TotalRow(s.total, formatMoney(_money(totals['total']), lang), bold: true),
            const SizedBox(height: 16),
            FilledButton(onPressed: cart['can_checkout'] == true ? () => context.push('/checkout') : null, child: Text(s.checkout)),
          ]);
        },
      ),
    );
  }
}

class _CartLine extends ConsumerWidget {
  const _CartLine({required this.item});
  final Map<String, dynamic> item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = _lang(context);
    final p = item['part'] as Map<String, dynamic>;
    final qty = double.parse(item['quantity'] as String).round();
    Future<void> set(int q) async {
      final api = ref.read(apiProvider);
      final ok = await runAction(context, () => q <= 0 ? api.delete('/me/cart/items/${p['id']}') : api.put('/me/cart/items/${p['id']}', {'quantity': q}));
      if (ok) ref.invalidate(cartProvider);
    }

    return Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('${ltr(p['part_number'] as String)} · ${p['brand']}', style: const TextStyle(fontWeight: FontWeight.w700)),
      Text('${p['name'] ?? ''}'),
      Text(formatMoney(_money(item['unit_price']), lang)),
      if (item['previous_price'] != null)
        Text(s.priceChanged(formatMoney(_money(item['previous_price']), lang), formatMoney(_money(item['unit_price']), lang)),
            style: const TextStyle(color: Color(0xFFB26A00), fontWeight: FontWeight.w600)),
      if (item['problem'] != null) Text(s.cartProblem(item['problem'] as String), style: TextStyle(color: Theme.of(context).colorScheme.error)),
      Row(children: [
        IconButton.outlined(onPressed: () => set(qty - 1), icon: Icon(qty <= 1 ? Icons.delete_outline : Icons.remove), tooltip: qty <= 1 ? s.remove : null),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Text('$qty', style: Theme.of(context).textTheme.titleMedium)),
        IconButton.outlined(onPressed: () => set(qty + 1), icon: const Icon(Icons.add)),
        const Spacer(),
        Text(formatMoney(_money(item['line_subtotal']), lang), style: const TextStyle(fontWeight: FontWeight.w700)),
      ]),
    ])));
  }
}

class _TotalRow extends StatelessWidget {
  const _TotalRow(this.label, this.value, {this.bold = false});
  final String label;
  final String value;
  final bool bold;

  @override
  Widget build(BuildContext context) {
    final style = bold ? const TextStyle(fontWeight: FontWeight.w800, fontSize: 16) : null;
    return Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(children: [Text(label, style: style), const Spacer(), Text(value, style: style)]));
  }
}

/// M-11 (2): التوصيل أو الاستلام، ثم المراجعة والتأكيد (C-11)
class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key});
  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  String fulfillment = 'pickup';
  String? branch;
  String? address;
  bool accepted = false;
  bool sending = false;
  final notes = TextEditingController();

  @override
  void dispose() {
    notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    final account = ref.watch(selectedAccountProvider);
    final addresses = (ref.watch(addressesProvider).value ?? const [])
        .where((a) => accountOf(a) == account && (a['kind'] == 'delivery' || a['kind'] == 'both')).toList();
    if (fulfillment == 'delivery' && address == null && addresses.isNotEmpty) address = addresses.first['id'] as String;
    final choice = (fulfillment: fulfillment, branch: fulfillment == 'pickup' ? branch : null, address: fulfillment == 'delivery' ? address : null, account: account);
    final async = ref.watch(checkoutProvider(choice));

    return Scaffold(
      appBar: AppBar(title: Text(s.checkout)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(checkoutProvider(choice))),
        data: (p) {
          final branches = (p['branches'] as List).cast<Map<String, dynamic>>();
          final fees = (p['delivery_fees'] as Map).cast<String, dynamic>();
          final totals = p['totals'] as Map<String, dynamic>;
          final open = p['open_orders'] as Map<String, dynamic>;
          final problem = p['problem'] as String?;
          final items = ((p['cart'] as Map)['items'] as List).cast<Map<String, dynamic>>();
          final chosenBranch = branch ?? p['branch_id'] as String? ?? branches.where((b) => b['can_fulfill'] == true).firstOrNull?['id'] as String?;
          return ListView(padding: screenPadding(context), children: [
            const AccountPicker(),
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'pickup', label: Text(s.pickup)), ButtonSegment(value: 'delivery', label: Text(s.delivery))],
              selected: {fulfillment},
              onSelectionChanged: (v) => setState(() => fulfillment = v.first),
            ),
            const SizedBox(height: 12),
            if (fulfillment == 'pickup')
              Choice(
                label: s.branch,
                value: chosenBranch,
                options: {for (final b in branches) b['id'] as String: '${b['name']}${b['can_fulfill'] == true ? '' : ' (${s.notEnoughHere})'}'},
                onChanged: (v) => setState(() => branch = v),
              )
            else if (addresses.isEmpty)
              Card(child: ListTile(title: Text(s.noDeliveryAddresses), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/account/addresses')))
            else
              Choice(
                label: s.deliveryAddress,
                value: address,
                options: {
                  for (final a in addresses)
                    a['id'] as String: '${a['label']} · ${s.governorateName(a['governorate'] as String)} · '
                        '${fees[a['governorate']] == null ? s.noDelivery : formatMoney(_money(fees[a['governorate']]), lang)}',
                },
                onChanged: (v) => setState(() => address = v),
              ),
            if (problem != null) Text(s.checkoutProblem(problem), style: TextStyle(color: Theme.of(context).colorScheme.error)),
            const Divider(height: 24),
            for (final i in items)
              Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(children: [
                Expanded(child: Text('${ltr((i['part'] as Map)['part_number'] as String)} × ${formatQty(i['quantity'] as String)}')),
                Text(formatMoney(_money(i['line_subtotal']), lang)),
              ])),
            const Divider(),
            _TotalRow(s.subtotal, formatMoney(_money(totals['subtotal']), lang)),
            if (fulfillment == 'delivery') _TotalRow(s.deliveryFee, formatMoney(_money(totals['delivery_fee']), lang)),
            _TotalRow(s.vat, formatMoney(_money(totals['vat_total']), lang)),
            _TotalRow(s.total, formatMoney(_money(totals['total']), lang), bold: true),
            const SizedBox(height: 12),
            Card(child: ListTile(title: Text(s.payOnFulfillment), subtitle: Text(s.payOnFulfillmentText))),
            if (p['needs_phone_confirmation'] == true) Text(s.willCall, style: const TextStyle(color: Color(0xFFB26A00), fontWeight: FontWeight.w600)),
            if (open['blocked'] == true) Text(s.openLimit(open['count'] as int), style: TextStyle(color: Theme.of(context).colorScheme.error)),
            const SizedBox(height: 8),
            Input(notes, s.notesForBranch, lines: 2, maxLength: 1000),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: accepted,
              onChanged: (v) => setState(() => accepted = v == true),
              title: Text(s.acceptReturns),
              secondary: IconButton(icon: const Icon(Icons.open_in_new), onPressed: () => context.push('/account/policies/returns')),
            ),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: problem == null && accepted && open['blocked'] != true && !sending ? () => _place(chosenBranch, account) : null,
              child: Text(s.placeOrder),
            ),
          ]);
        },
      ),
    );
  }

  Future<void> _place(String? chosenBranch, String account) async {
    setState(() => sending = true);
    final api = ref.read(apiProvider);
    Map<String, dynamic>? order;
    final ok = await runAction(context, () async {
      order = await api.post('/me/orders', {
        'fulfillment': fulfillment,
        'branch_id': fulfillment == 'pickup' ? chosenBranch : null,
        'address_id': fulfillment == 'delivery' ? address : null,
        'organization_id': organizationOf(account),
        'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
        'accept_returns_policy': true,
      });
    });
    if (!mounted) return;
    setState(() => sending = false);
    if (!ok || order == null) {
      ref.invalidate(cartProvider);
      return;
    }
    ref.invalidate(cartProvider);
    ref.invalidate(orderListProvider);
    toast(context, S.of(context).placed);
    context.go('/requests');
    context.push('/orders/${order!['id']}');
  }
}

/// M-16 تبويب طلبات القطع
class OrderLists extends ConsumerStatefulWidget {
  const OrderLists({super.key});
  @override
  ConsumerState<OrderLists> createState() => _OrderListsState();
}

class _OrderListsState extends ConsumerState<OrderLists> {
  static const tabs = ['open', 'delivered', 'cancelled'];
  String tab = 'open';

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(orderListProvider(tab));
    final counts = (async.value?.meta['counts'] as Map?)?.cast<String, dynamic>() ?? const {};
    return RefreshIndicator(
      onRefresh: () => ref.refresh(orderListProvider(tab).future),
      child: ListView(padding: const EdgeInsets.all(12), children: [
        Row(children: [
          for (final t in tabs)
            Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: ChoiceChip(
              label: Text('${s.orderTab(t)}${(counts[t] ?? 0) > 0 ? ' (${counts[t]})' : ''}'),
              selected: tab == t, onSelected: (_) => setState(() => tab = t))),
        ]),
        const SizedBox(height: 8),
        OutlinedButton.icon(onPressed: () => context.push('/cart'), icon: const Icon(Icons.shopping_cart_outlined), label: Text(s.cart)),
        const SizedBox(height: 8),
        ...async.when(
          loading: () => [const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))],
          error: (_, _) => [LoadError(onRetry: () => ref.invalidate(orderListProvider(tab)))],
          data: (page) => page.rows.isEmpty
              ? [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.noOrders)))]
              : [
                  for (final o in page.rows)
                    Card(child: ListTile(
                      onTap: () => context.push('/orders/${o['id']}'),
                      title: Text('${ltr(o['number'] as String)} · ${s.fulfillment(o['fulfillment'] as String)}'),
                      subtitle: Text([
                        (o['summary'] as List).join(s.listSeparator),
                        if (o['account'] != null) (o['account'] as Map)['name'],
                      ].join('\n')),
                      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(s.orderStatus(o['status'] as String), style: TextStyle(color: _statusColors[o['status']], fontWeight: FontWeight.w700)),
                        Text(formatMoney(_money(o['total']), lang)),
                      ]),
                    )),
                ],
        ),
      ]),
    );
  }
}

/// M-19 تفاصيل طلب القطع: رمز الاستلام بخط كبير، والمهلة، والمستحق، والفاتورة، والإلغاء والإرجاع
class OrderDetailScreen extends ConsumerWidget {
  const OrderDetailScreen({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(orderProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text(async.value?['number'] as String? ?? '')),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(orderProvider(id))),
        data: (o) {
          final t = Theme.of(context).textTheme;
          final totals = o['totals'] as Map<String, dynamic>;
          final items = (o['items'] as List).cast<Map<String, dynamic>>();
          final events = (o['timeline'] as List).cast<Map<String, dynamic>>();
          final returns = (o['returns'] as List).cast<Map<String, dynamic>>();
          final invoice = o['invoice'] as Map<String, dynamic>?;
          final balance = double.tryParse((totals['balance'] as Map?)?['amount'] as String? ?? '0') ?? 0;
          final status = o['status'] as String;
          return RefreshIndicator(
            onRefresh: () => ref.refresh(orderProvider(id).future),
            child: ListView(padding: screenPadding(context), children: [
              Text('${s.fulfillment(o['fulfillment'] as String)} · ${s.orderStatus(status)}', style: t.titleMedium?.copyWith(color: _statusColors[status])),
              if (o['account'] != null) Text((o['account'] as Map)['name'] as String),
              if (status == 'ready_for_pickup' && o['pickup_code'] != null)
                Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(children: [
                  Text(s.pickupCode, style: t.titleMedium),
                  Text(o['pickup_code'] as String, textDirection: TextDirection.ltr,
                      style: t.displayMedium?.copyWith(fontWeight: FontWeight.w900, letterSpacing: 8)),
                  Text(s.pickupCodeHint, textAlign: TextAlign.center),
                  if (o['pickup_deadline_at'] != null) Text(s.collectBefore(formatDateTime(o['pickup_deadline_at'] as String, lang)), style: t.titleSmall),
                  if (o['branch_detail'] != null) Text('${(o['branch_detail'] as Map)['name']}'),
                ]))),
              if (balance > 0 && status != 'cancelled')
                ListTile(contentPadding: EdgeInsets.zero, title: Text('${s.dueOnPickup}: ${formatMoney(_money(totals['balance']), lang)}'), subtitle: Text(s.payOnFulfillmentText)),
              if (o['cancel_reason'] != null) Text(o['cancel_reason'] as String, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              const SizedBox(height: 8),
              Text(s.items, style: t.titleMedium),
              for (final i in items)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('${ltr(i['part_number'] as String)} · ${i['name']}'),
                  subtitle: Text('${formatQty(i['quantity'] as String)} × ${formatMoney(_money(i['unit_price']), lang)}${i['is_returnable'] == true ? '' : ' · ${s.notReturnableShort}'}'),
                  trailing: Text(formatMoney(_money(i['line_subtotal']), lang)),
                ),
              const Divider(),
              _TotalRow(s.subtotal, formatMoney(_money(totals['subtotal']), lang)),
              if (o['fulfillment'] == 'delivery') _TotalRow(s.deliveryFee, formatMoney(_money(totals['delivery_fee']), lang)),
              _TotalRow(s.vat, formatMoney(_money(totals['vat_total']), lang)),
              _TotalRow(s.total, formatMoney(_money(totals['total']), lang), bold: true),
              _TotalRow(s.paid, formatMoney(_money(totals['paid']), lang)),
              if (invoice?['pdf_url'] != null)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.picture_as_pdf_outlined),
                  title: Text('${s.invoice} ${ltr(invoice!['number'] as String? ?? '')}'),
                  trailing: Text(s.openPdf),
                  onTap: () => openExternal(invoice['pdf_url'] as String),
                ),
              const SizedBox(height: 8),
              Text(s.timeline, style: t.titleMedium),
              for (final e in events)
                ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.circle, size: 10),
                    title: Text(s.orderStatus(e['status'] as String)),
                    subtitle: Text([if (e['at'] != null) formatDateTime(e['at'] as String, lang), if (e['note'] != null) e['note']].join(' · '))),
              if (returns.isNotEmpty) ...[
                Text(s.returns, style: t.titleMedium),
                for (final r in returns)
                  ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(s.returnStatus(r['status'] as String)),
                      subtitle: Text([r['reason'], if (r['decision_note'] != null) r['decision_note']].join('\n'))),
              ],
              const SizedBox(height: 12),
              if (o['can_return'] == true)
                OutlinedButton(onPressed: () => context.push('/orders/$id/return'), child: Text(s.requestReturn)),
              if (o['can_cancel'] == true)
                TextButton(
                  style: TextButton.styleFrom(foregroundColor: Theme.of(context).colorScheme.error),
                  onPressed: () async {
                    if (!await askConfirm(context, s.cancelOrderQ) || !context.mounted) return;
                    if (await runAction(context, () => ref.read(apiProvider).post('/me/orders/$id/cancel', {}), done: s.orderCancelled)) {
                      ref.invalidate(orderProvider(id));
                      ref.invalidate(orderListProvider);
                    }
                  },
                  child: Text(s.cancelOrder),
                ),
              if (status == 'processing') Text(s.preparingContact, style: t.bodySmall),
            ]),
          );
        },
      ),
    );
  }
}

/// M-19 طلب الإرجاع: البنود القابلة للإرجاع وكمياتها، والسبب، والصور (BR-12)
class ReturnRequestScreen extends ConsumerStatefulWidget {
  const ReturnRequestScreen({super.key, required this.id});
  final String id;
  @override
  ConsumerState<ReturnRequestScreen> createState() => _ReturnRequestScreenState();
}

class _ReturnRequestScreenState extends ConsumerState<ReturnRequestScreen> {
  final picked = <String, int>{};
  final photos = <String>[];
  final reason = TextEditingController();
  Map<String, String> errors = const {};
  bool sending = false;

  @override
  void dispose() {
    reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(orderProvider(widget.id));
    return Scaffold(
      appBar: AppBar(title: Text(s.requestReturn)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(orderProvider(widget.id))),
        data: (o) {
          final items = (o['items'] as List).cast<Map<String, dynamic>>().where((i) => (double.tryParse(i['returnable_quantity'] as String) ?? 0) > 0);
          return ListView(padding: screenPadding(context), children: [
            if (o['return_until'] != null) Text(s.returnUntil(formatDateTime(o['return_until'] as String, lang))),
            const SizedBox(height: 8),
            for (final i in items) _line(i),
            Input(reason, s.returnReason, lines: 2, maxLength: 1000, error: errors['reason']),
            if (errors.isNotEmpty && errors['reason'] == null) Text(errors.values.first, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            OutlinedButton.icon(
              icon: const Icon(Icons.photo_camera_outlined),
              label: Text('${s.addPhoto}${photos.isEmpty ? '' : ' (${photos.length})'}'),
              onPressed: photos.length >= 6 ? null : _addPhoto,
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: picked.isEmpty || sending ? null : _send, child: Text(s.sendReturn)),
          ]);
        },
      ),
    );
  }

  Widget _line(Map<String, dynamic> i) {
    final id = i['id'] as String;
    final max = double.parse(i['returnable_quantity'] as String).floor();
    final qty = picked[id];
    return Card(child: Padding(padding: const EdgeInsets.symmetric(horizontal: 8), child: Row(children: [
      Checkbox(value: qty != null, onChanged: (v) => setState(() => v == true ? picked[id] = 1 : picked.remove(id))),
      Expanded(child: Text('${ltr(i['part_number'] as String)} · ${i['name']}')),
      if (qty != null) ...[
        IconButton(onPressed: qty > 1 ? () => setState(() => picked[id] = qty - 1) : null, icon: const Icon(Icons.remove)),
        Text('$qty'),
        IconButton(onPressed: qty < max ? () => setState(() => picked[id] = qty + 1) : null, icon: const Icon(Icons.add)),
      ],
    ])));
  }

  Future<void> _addPhoto() async {
    final f = await pickAttachment(context);
    if (f == null) return;
    try {
      final up = await ref.read(apiProvider).upload(f.path, f.name);
      setState(() => photos.add(up['id'] as String));
    } on ApiException catch (e) {
      if (mounted) toast(context, failureText(e, S.of(context)));
    }
  }

  Future<void> _send() async {
    final s = S.of(context);
    setState(() => sending = true);
    try {
      await ref.read(apiProvider).post('/me/orders/${widget.id}/returns', {
        'items': [for (final e in picked.entries) {'order_item_id': e.key, 'quantity': e.value}],
        'reason': reason.text.trim(),
        'attachment_ids': photos,
      });
      ref.invalidate(orderProvider(widget.id));
      if (!mounted) return;
      toast(context, s.returnSent);
      if (context.canPop()) context.pop();
    } on ApiException catch (e) {
      setState(() {
        errors = fieldErrors(e);
        sending = false;
      });
      if (errors.isEmpty && mounted) toast(context, failureText(e, s));
    }
  }
}

/// M-23 الفواتير: القائمة، والمتأخرة معلّمة، وفتح PDF ومشاركته من عارض الجهاز
class InvoicesScreen extends ConsumerWidget {
  const InvoicesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(invoicesProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.invoices)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(invoicesProvider)),
        data: (page) => page.rows.isEmpty
            ? Center(child: Text(s.noInvoices))
            : RefreshIndicator(
                onRefresh: () => ref.refresh(invoicesProvider.future),
                child: ListView(padding: screenPadding(context, 12), children: [
                  for (final i in page.rows)
                    Card(child: ListTile(
                      onTap: i['pdf_url'] == null ? null : () => openExternal(i['pdf_url'] as String),
                      title: Text('${ltr(i['number'] as String? ?? '')} · ${s.invoiceSource((i['source'] as Map)['type'] as String)}'),
                      subtitle: Text([
                        if (i['due_date'] != null) s.dueOn(ltr(i['due_date'] as String)),
                        if (i['account'] != null) (i['account'] as Map)['name'],
                        for (final c in (i['credit_notes'] as List).cast<Map<String, dynamic>>()) '${s.creditNote} ${ltr(c['number'] as String)}',
                      ].join('\n')),
                      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(i['overdue'] == true ? s.overdue : s.invoiceStatus(i['status'] as String),
                            style: TextStyle(fontWeight: FontWeight.w700, color: i['overdue'] == true ? Theme.of(context).colorScheme.error : null)),
                        Text(formatMoney(_money(i['total']), lang)),
                      ]),
                    )),
                ]),
              ),
      ),
    );
  }
}
