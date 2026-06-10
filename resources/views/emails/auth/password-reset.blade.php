<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body { font-family: Georgia, serif; background: #f5f0eb; color: #2C1A0E; margin: 0; padding: 40px 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 4px; overflow: hidden; }
        .header { background: #2C1A0E; padding: 32px 40px; }
        .header h1 { color: #C5392A; margin: 0; font-size: 24px; letter-spacing: 0.1em; text-transform: uppercase; }
        .body { padding: 40px; }
        .body p { line-height: 1.7; margin: 0 0 20px; }
        .button { display: inline-block; background: #C5392A; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 2px; font-size: 15px; font-weight: bold; letter-spacing: 0.05em; margin: 8px 0 24px; }
        .footer { padding: 24px 40px; border-top: 1px solid #e8e0d8; color: #7a6552; font-size: 13px; }
        .footer a { color: #C5392A; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>American Headhunter</h1>
        </div>
        <div class="body">
            <p>Hello{{ $firstName ? ' ' . $firstName : '' }},</p>
            <p>We received a request to reset the password for your American Headhunter account. Click the button below to choose a new password.</p>
            <p>
                <a href="{{ $resetUrl }}" class="button">Reset Password</a>
            </p>
            <p>This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email — your password will not change.</p>
            <p>If the button above doesn't work, paste this URL into your browser:<br>
            <a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} American Headhunter · <a href="{{ config('app.url') }}/privacy">Privacy Policy</a> · <a href="{{ config('app.url') }}/terms">Terms of Service</a></p>
        </div>
    </div>
</body>
</html>
