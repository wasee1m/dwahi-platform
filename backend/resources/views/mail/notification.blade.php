<!doctype html>
<html lang="{{ $lang }}" dir="{{ $lang === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f6f1e7;font-family:Tahoma,Arial,sans-serif;color:#231f1a">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:28px">
        <tr><td style="font-size:13px;color:#8a7f70;padding-bottom:16px">{{ $lang === 'en' ? 'DWAHI-ALBADIA' : 'ضواحي البادية' }}</td></tr>
        <tr><td style="font-size:20px;font-weight:bold;padding-bottom:12px">{{ $title }}</td></tr>
        <tr><td style="font-size:15px;line-height:1.7;padding-bottom:24px;white-space:pre-line">{{ $body }}</td></tr>
        <tr><td><a href="{{ $url }}" style="display:inline-block;background:#f2a900;color:#231f1a;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:4px">{{ $action }}</a></td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
