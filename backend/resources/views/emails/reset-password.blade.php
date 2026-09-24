{{--
    قالب رسالة إعادة تعيين كلمة السر.

    الأنماط داخل الوسوم لا في <style>: جيميل يحذف كتلة <style> من رأس
    الرسالة، فما لم يكن على الوسم نفسه يسقط. والتخطيط بجداول لا بـflex
    ولا grid للسبب نفسه — عملاء البريد يتخلّفون عن المتصفحات بسنين.

    الألوان من هوية الموقع في style.css: الزيتوني #3c4a2f والنحاسي
    #b06a35 على ورق رملي دافئ #f4efe4.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>إعادة تعيين كلمة السر</title>
</head>
<body style="margin:0;padding:0;background:#f4efe4;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#f4efe4;padding:28px 12px;">
<tr>
<td align="center">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="max-width:560px;background:#fbf8f0;border:1px solid #ddd3bf;border-radius:14px;">

    <tr>
      <td dir="rtl" align="right"
          style="padding:26px 30px 0;font-family:'Tajawal','Segoe UI',Arial,sans-serif;">
        <div style="font-size:19px;font-weight:700;color:#3c4a2f;">{{ $appName }}</div>
        <div style="font-size:13px;color:#6b6355;padding-top:4px;">
          كلية فلسطين التقنية — هندسة أنظمة الحاسوب
        </div>
      </td>
    </tr>

    <tr>
      <td style="padding:20px 30px 0;">
        <div style="height:1px;background:#ddd3bf;line-height:1px;font-size:0;">&nbsp;</div>
      </td>
    </tr>

    <tr>
      <td dir="rtl" align="right"
          style="padding:22px 30px 0;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
                 font-size:16px;line-height:1.9;color:#2a2620;">
        <p style="margin:0 0 14px;font-size:20px;font-weight:700;color:#3c4a2f;">
          إعادة تعيين كلمة السر
        </p>
        <p style="margin:0 0 18px;">
          وصلنا طلبٌ لإعادة تعيين كلمة سرّ حسابك. اضغط الزرّ أدناه لاختيار
          كلمة سرّ جديدة.
        </p>
      </td>
    </tr>

    <tr>
      <td align="center" style="padding:6px 30px 4px;">
        <a href="{{ $url }}"
           style="display:inline-block;background:#3c4a2f;color:#f4efe4;
                  font-family:'Tajawal','Segoe UI',Arial,sans-serif;font-size:16px;
                  font-weight:700;text-decoration:none;padding:13px 40px;border-radius:9px;">
          تعيين كلمة سرّ جديدة
        </a>
      </td>
    </tr>

    {{--
        الرابط عاريًا تحت الزرّ: بعض عملاء البريد يجرّدون الأزرار أو
        يمنعون الضغط عليها، فيبقى النسخ واللصق مخرجًا.
    --}}
    <tr>
      <td dir="rtl" align="right"
          style="padding:20px 30px 0;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
                 font-size:13px;line-height:1.8;color:#6b6355;">
        <p style="margin:0 0 6px;">إن لم يعمل الزرّ، انسخ هذا الرابط والصقه في المتصفح:</p>
        <p dir="ltr" style="margin:0;padding:10px 12px;background:#efe8d8;border-radius:9px;
                            font-family:'Courier New',monospace;font-size:12px;
                            color:#2a2620;word-break:break-all;text-align:left;">
          {{ $url }}
        </p>
      </td>
    </tr>

    <tr>
      <td dir="rtl" align="right"
          style="padding:20px 30px 26px;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
                 font-size:14px;line-height:1.9;color:#6b6355;">
        <p style="margin:0 0 8px;">
          <span style="color:#b06a35;font-weight:700;">الرابط يصلح {{ $minutes }} دقيقة</span>
          من لحظة إرساله، ثم يلزمك طلب رابط جديد.
        </p>
        <p style="margin:0;">
          إن لم تطلب هذا فتجاهل الرسالة — كلمة سرّك لم تتغيّر ولن تتغيّر.
        </p>
      </td>
    </tr>

  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="max-width:560px;">
  <tr>
    <td dir="rtl" align="center"
        style="padding:16px 20px 0;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
               font-size:12px;color:#9a9182;">
      رسالة آلية من {{ $appName }} — لا تردّ عليها.
    </td>
  </tr>
  </table>

</td>
</tr>
</table>

</body>
</html>
