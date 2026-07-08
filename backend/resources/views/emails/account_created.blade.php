<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Account Has Been Created</title>
</head>
<body style="margin:0; padding:0; background:#f4f8fb;">
    <table width="100%" bgcolor="#f4f8fb" cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin:40px 0;">
                    <tr>
                        <td style="background:#2563eb; padding:32px 0; border-radius:8px 8px 0 0;" align="center">
                            <h1 style="color:#fff; margin:0; font-size:28px; letter-spacing:1px;">SimpLinkX Account Created</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="font-size:18px; color:#222; margin-bottom:24px;">
                                Hello,
                            </p>
                            <p style="font-size:16px; color:#222; margin-bottom:24px;">
                                Your account has been created by our administrator. Here are your login credentials:
                            </p>
                            <table cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:24px;">
                                <tr>
                                    <td style="padding:8px 0; font-size:16px; color:#2563eb; width:120px;">Email:</td>
                                    <td style="padding:8px 0; font-size:16px; color:#222;">{{ $email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0; font-size:16px; color:#2563eb;">Password:</td>
                                    <td style="padding:8px 0; font-size:16px; color:#222;">{{ $password }}</td>
                                </tr>
                            </table>
                            <div style="text-align:center; margin-bottom:32px;">
                                <a href="{{ $loginUrl }}" style="background:#2563eb; color:#fff; text-decoration:none; padding:12px 32px; border-radius:4px; font-size:16px; font-weight:bold; display:inline-block;">
                                    Login to Your Account
                                </a>
                            </div>
                            <p style="font-size:15px; color:#222; margin-bottom:16px;">
                                <strong>Important:</strong> For your security, please log in and change your password immediately after your first login.
                            </p>
                            <p style="font-size:14px; color:#888;">
                                If you have any questions or did not request this account, please contact our support team.
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