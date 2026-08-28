<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Votre code de vérification</title>
</head>
<body style="margin:0; padding:0; background-color:#F8F7FF; font-family:Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:420px; background:#FFFFFF; border-radius:16px; padding:32px 28px;">
                    <tr>
                        <td style="font-size:20px; font-weight:700; color:#1E1B4B; padding-bottom:12px;">
                            Miva Fid
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:15px; color:#374151; line-height:1.6; padding-bottom:20px;">
                            Voici votre code de vérification pour réinitialiser votre mot de passe :
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom:20px;">
                            <span style="display:inline-block; font-size:32px; font-weight:700; letter-spacing:6px; color:#7C3AED; background:#F3E8FF; padding:14px 24px; border-radius:12px;">
                                {{ $code }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:13px; color:#6B7280; line-height:1.6;">
                            Ce code expire dans 10 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
