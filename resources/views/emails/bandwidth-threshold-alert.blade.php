<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bandwidth Threshold Alert</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 0;">
<tr>
<td align="center">
<table role="presentation" width="570" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden;">
<tr>
<td style="background-color:#18181b; padding:32px; text-align:center;">
<h1 style="margin:0; color:#ffffff; font-size:20px; font-weight:600;">Tuwa NOC</h1>
</td>
</tr>
<tr>
<td style="padding:32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr>
<td style="background-color:#fffbeb; border-left:4px solid #d97706; padding:16px; border-radius:4px;">
<p style="margin:0; color:#92400e; font-size:14px; font-weight:600;">BANDWIDTH THRESHOLD EXCEEDED</p>
</td>
</tr>
</table>
<p style="margin:0 0 16px 0; color:#18181b; font-size:15px; line-height:1.5;">
{{ $device->name }}'s {{ $direction }}bound traffic has exceeded the configured alert threshold.
</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0; border:1px solid #e4e4e7; border-radius:6px;">
<tr>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#71717a; font-size:13px; width:140px;">Device</td>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#18181b; font-size:13px; font-weight:600;">{{ $device->name }}</td>
</tr>
<tr>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#71717a; font-size:13px;">IP Address</td>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#18181b; font-size:13px;">{{ $device->ip_address }}</td>
</tr>
<tr>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#71717a; font-size:13px;">Direction</td>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#18181b; font-size:13px;">{{ ucfirst($direction) }}bound</td>
</tr>
<tr>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#71717a; font-size:13px;">Current</td>
<td style="padding:12px 16px; border-bottom:1px solid #e4e4e7; color:#d97706; font-size:13px; font-weight:600;">{{ number_format($currentBps / 1000, 1) }} Kbps</td>
</tr>
<tr>
<td style="padding:12px 16px; color:#71717a; font-size:13px;">Threshold</td>
<td style="padding:12px 16px; color:#18181b; font-size:13px;">{{ number_format($thresholdBps / 1000, 1) }} Kbps</td>
</tr>
</table>
<p style="margin:0; color:#71717a; font-size:13px;">Detected at {{ now()->toDayDateTimeString() }}</p>
</td>
</tr>
<tr>
<td style="padding:24px 32px; background-color:#fafafa; border-top:1px solid #e4e4e7; text-align:center;">
<p style="margin:0; color:#a1a1aa; font-size:12px;">© {{ date('Y') }} Tuwa NOC. All rights reserved.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
