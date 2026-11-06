<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>New enquiry</title>
    </head>
    <body style="margin:0;padding:24px;background:#F6F2E9;color:#2B312A;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #DED8CB;border-radius:12px;overflow:hidden;">
            <div style="background:#2F3B2B;padding:24px 28px;color:#F6F2E9;">
                <p style="margin:0;font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.7;">{{ $propertyName }}</p>
                <h1 style="margin:8px 0 0;font-size:24px;font-weight:600;">New enquiry from the website</h1>
            </div>

            <div style="padding:28px;">
                <p style="margin:0 0 16px;font-size:14px;line-height:1.7;">You received a new message from the public contact form.</p>

                <table style="width:100%;border-collapse:collapse;font-size:14px;line-height:1.7;">
                    <tr>
                        <td style="padding:6px 0;width:120px;color:#6E7267;">Name</td>
                        <td style="padding:6px 0;font-weight:600;">{{ $senderName }}</td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#6E7267;">Email</td>
                        <td style="padding:6px 0;"><a href="mailto:{{ $senderEmail }}" style="color:#2F3B2B;">{{ $senderEmail }}</a></td>
                    </tr>
                    @if ($senderPhone)
                        <tr>
                            <td style="padding:6px 0;color:#6E7267;">Phone</td>
                            <td style="padding:6px 0;">{{ $senderPhone }}</td>
                        </tr>
                    @endif
                    @if ($villa)
                        <tr>
                            <td style="padding:6px 0;color:#6E7267;">Villa</td>
                            <td style="padding:6px 0;">{{ $villa }}</td>
                        </tr>
                    @endif
                </table>

                <div style="margin-top:20px;padding-top:20px;border-top:1px solid #DED8CB;">
                    <p style="margin:0 0 8px;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#6E7267;">Message</p>
                    <p style="margin:0;font-size:14px;line-height:1.8;white-space:pre-line;">{{ $messageBody }}</p>
                </div>

                <p style="margin:24px 0 0;font-size:12px;line-height:1.6;color:#6E7267;">
                    Reply directly to this email to answer {{ $senderName }}.
                </p>
            </div>
        </div>
    </body>
</html>
