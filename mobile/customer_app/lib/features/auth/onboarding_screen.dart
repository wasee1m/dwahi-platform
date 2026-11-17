import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api.dart';
import '../../core/strings.dart';
import 'auth_controller.dart';
import 'error_text.dart';

/// M-05 إكمال الملف (FR-AUTH-01، US-06): الاسم، فرد/شركة، الموافقات (التسويقية غير مفعلة افتراضياً)
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _company = TextEditingController();
  bool _isCompany = false;
  String? _governorate;
  bool _terms = false, _privacy = false, _marketing = false, _busy = false;
  String? _error;
  Map<String, dynamic> _fieldErrors = {};

  Future<void> _submit() async {
    final s = S.of(context);
    if (!_form.currentState!.validate() || !_terms || !_privacy) {
      setState(() => _error = (!_terms || !_privacy) ? '${s.acceptTerms} · ${s.acceptPrivacy}' : null);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = {};
    });
    try {
      await ref.read(authProvider.notifier).completeOnboarding({
        'full_name': _name.text.trim(),
        'account_type': _isCompany ? 'company' : 'individual',
        if (_isCompany) 'company_name': _company.text.trim(),
        if (_isCompany) 'governorate': _governorate,
        'accept_terms': _terms,
        'accept_privacy': _privacy,
        'marketing': _marketing,
      });
      if (mounted) context.go('/home');
    } catch (e) {
      setState(() {
        _error = errorText(e, s);
        if (e is ApiException && e.code == 'VALIDATION_FAILED') _fieldErrors = e.details;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _fieldError(String k) => (_fieldErrors[k] as List?)?.first as String?;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.onboardingTitle)),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            TextFormField(
              controller: _name,
              autofillHints: const [AutofillHints.name],
              decoration: InputDecoration(labelText: s.fullName, errorText: _fieldError('full_name')),
              validator: (v) => (v ?? '').trim().isEmpty ? s.required : null,
            ),
            const SizedBox(height: 16),
            SegmentedButton<bool>(
              segments: [ButtonSegment(value: false, label: Text(s.individual)), ButtonSegment(value: true, label: Text(s.company))],
              selected: {_isCompany},
              onSelectionChanged: (v) => setState(() => _isCompany = v.first),
            ),
            if (_isCompany) ...[
              const SizedBox(height: 16),
              TextFormField(
                controller: _company,
                decoration: InputDecoration(labelText: s.companyName, errorText: _fieldError('company_name')),
                validator: (v) => _isCompany && (v ?? '').trim().isEmpty ? s.required : null,
              ),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: _governorate,
                decoration: InputDecoration(labelText: s.governorate, errorText: _fieldError('governorate')),
                items: [for (final g in S.governorates.keys) DropdownMenuItem(value: g, child: Text(s.governorateName(g)))],
                onChanged: (v) => setState(() => _governorate = v),
                validator: (v) => _isCompany && v == null ? s.required : null,
              ),
            ],
            const SizedBox(height: 16),
            CheckboxListTile(value: _terms, onChanged: (v) => setState(() => _terms = v!), title: Text(s.acceptTerms), contentPadding: EdgeInsets.zero),
            CheckboxListTile(value: _privacy, onChanged: (v) => setState(() => _privacy = v!), title: Text(s.acceptPrivacy), contentPadding: EdgeInsets.zero),
            CheckboxListTile(value: _marketing, onChanged: (v) => setState(() => _marketing = v!), title: Text(s.marketing), contentPadding: EdgeInsets.zero),
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
            const SizedBox(height: 24),
            FilledButton(onPressed: _busy ? null : _submit, child: Text(s.createAccount)),
          ],
        ),
      ),
    );
  }
}
