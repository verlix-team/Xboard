<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Notification</title></head>
<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:Arial,sans-serif;">
<table role="presentation" style="width:100%;max-width:600px;margin:24px auto;background:#fff;padding:28px;border-collapse:separate;">
<tr><td style="font-size:18px;font-weight:600;padding-bottom:18px;">{{$name}}</td></tr>
<tr><td><h1 style="font-size:22px;margin:0 0 18px;">Notification</h1></td></tr>
<tr><td style="font-size:15px;line-height:1.7;white-space:pre-wrap;"><div>{!! nl2br($content) !!}</div><p><a href="{{$url}}">View details</a></p></td></tr>
<tr><td style="font-size:12px;color:#71717a;padding-top:24px;">This email was sent automatically. Please do not reply.</td></tr>
</table>
</body>
</html>
