<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Your Password</title>
</head>
<body style="margin:0; padding:0; background:#f4f8fb;">
    <table width="100%" bgcolor="#f4f8fb" cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin:40px 0;">
                    <tr>
                        <td style="background:#2563eb; padding:32px 0; border-radius:8px 8px 0 0;" align="center">
                            <h1 style="color:#fff; margin:0; font-size:28px; letter-spacing:1px;">Reset Your Password</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="font-size:18px; color:#222; margin-bottom:24px;">
                                Hello,
                            </p>
                            <p style="font-size:16px; color:#222; margin-bottom:24px;">
                                We received a request to reset your password for your SimpLinkX account. If you did not make this request, please ignore this email.
                            </p>
                            <p style="font-size:16px; color:#222; margin-bottom:32px;">
                                To reset your password, click the button below:
                            </p>
                            <div style="text-align:center; margin-bottom:32px;">
                                <a href="{{ $resetUrl }}" style="background:#2563eb; color:#fff; text-decoration:none; padding:12px 32px; border-radius:4px; font-size:16px; font-weight:bold; display:inline-block;">
                                    Reset Password
                                </a>
                            </div>
                            <p style="font-size:15px; color:#222; margin-bottom:16px;">
                                <strong>Important:</strong> This password reset link will expire in 60 minutes for your security.
                            </p>
                            <p style="font-size:14px; color:#888; margin-bottom:16px;">
                                If the button above doesn't work, you can copy and paste the following link into your browser:
                            </p>
                            <p style="font-size:14px; color:#2563eb; word-break:break-all; margin-bottom:24px;">
                                {{ $resetUrl }}
                            </p>
                            <p style="font-size:14px; color:#888;">
                                If you have any questions or need assistance, please contact our support team.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f4f8fb; padding:16px 0; border-radius:0 0 8px 8px; text-align:center;">
                            <span style="color:#2563eb; font-size:14px;">&copy; {{ date('Y') }} SimpLinkX. All rights reserved.</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
