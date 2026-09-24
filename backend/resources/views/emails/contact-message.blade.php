{{--
    قالب رسالة «تواصل معنا» الواصلة لصندوق الموقع.

    القيود نفسها التي حكمت قالب إعادة التعيين: الأنماط داخل الوسوم لا في
    <style> لأن جيميل يحذف كتلة الرأس، والتخطيط بجداول لا بـflex ولا grid
    لأن عملاء البريد يتخلّفون عن المتصفحات بسنين.

    وهذه رسالة تُقرأ لا تُنقَر: لا زرّ فيها ولا رابط خارجيّ سوى mailto —
    قارئها هو صاحب الموقع في صندوقه، وما يحتاجه أن يرى من كتب وماذا كتب،
    ثم يضغط «رد» فيصل الطالب عبر Reply-To.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>استفسار جديد</title>
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
        <div style="font-size:19px;font-weight:700;color:#3c4a2f;">{{ config('app.name') }}</div>
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
        <p style="margin:0 0 16px;font-size:20px;font-weight:700;color:#3c4a2f;">
          استفسار جديد من الموقع
        </p>
      </td>
    </tr>

    {{--
        البيانات في جدول لا في أسطر نصّ: صاحب الموقع يمسح الرسالة بعينه
        بحثًا عن «من» و«ماذا»، والعمودان يجعلان ذلك نظرةً واحدة.
    --}}
    <tr>
      <td dir="rtl" align="right" style="padding:0 30px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="font-family:'Tajawal','Segoe UI',Arial,sans-serif;font-size:14.5px;
                      line-height:1.9;color:#2a2620;">
          <tr>
            <td width="90" valign="top" style="padding:5px 0;color:#6b6355;">الاسم</td>
            <td valign="top" style="padding:5px 0;font-weight:700;">{{ $senderName }}</td>
          </tr>
          <tr>
            <td width="90" valign="top" style="padding:5px 0;color:#6b6355;">البريد</td>
            <td valign="top" style="padding:5px 0;">
              <a href="mailto:{{ $senderEmail }}" dir="ltr"
                 style="color:#b06a35;text-decoration:none;font-weight:700;">{{ $senderEmail }}</a>
            </td>
          </tr>
          <tr>
            <td width="90" valign="top" style="padding:5px 0;color:#6b6355;">الموضوع</td>
            <td valign="top" style="padding:5px 0;font-weight:700;">{{ $subjectLine }}</td>
          </tr>
        </table>
      </td>
    </tr>

    {{--
        نصّ الرسالة مُهرَّب ثم تُدخَل عليه <br> — بهذا الترتيب لا عكسه.
        nl2br قبل e() يهرّب الوسوم التي أدخلها هو نفسه فتظهر نصًّا،
        وe() وحدها تطوي الرسالة كلها في فقرة واحدة بلا أسطر.
    --}}
    <tr>
      <td dir="rtl" align="right" style="padding:18px 30px 0;">
        <div style="padding:16px 18px;background:#f4efe4;border:1px solid #e6dcc7;
                    border-radius:10px;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
                    font-size:15px;line-height:1.95;color:#2a2620;
                    white-space:normal;word-break:break-word;">
          {!! nl2br(e($body)) !!}
        </div>
      </td>
    </tr>

    <tr>
      <td dir="rtl" align="right"
          style="padding:18px 30px 26px;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
                 font-size:13.5px;line-height:1.9;color:#6b6355;">
        <span style="color:#b06a35;font-weight:700;">اضغط «رد» على هذه الرسالة</span>
        فيصل ردّك {{ $senderName }} مباشرة.
      </td>
    </tr>

  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
         style="max-width:560px;">
  <tr>
    <td dir="rtl" align="center"
        style="padding:16px 20px 0;font-family:'Tajawal','Segoe UI',Arial,sans-serif;
               font-size:12px;color:#9a9182;">
      أُرسلت من نموذج «تواصل معنا» في {{ config('app.name') }}.
    </td>
  </tr>
  </table>

</td>
</tr>
</table>

</body>
</html>
