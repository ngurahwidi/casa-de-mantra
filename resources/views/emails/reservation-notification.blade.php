<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $subjectLine }}</title>
    </head>
    <body style="margin:0;padding:24px;background:#F6F2E9;color:#2B312A;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #DED8CB;border-radius:12px;overflow:hidden;">
            <div style="background:#2F3B2B;padding:24px 28px;color:#F6F2E9;">
                <p style="margin:0;font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.7;">{{ $propertyName }}</p>
                <h1 style="margin:8px 0 0;font-size:22px;font-weight:600;">{{ $subjectLine }}</h1>
            </div>

            <div style="padding:28px;">
                <p style="margin:0;font-size:14px;line-height:1.8;white-space:pre-line;">{!! $bodyHtml !!}</p>
            </div>
        </div>
    </body>
</html>
