<?php

namespace Database\Seeders\Communications;

use App\Models\Communications\EmailTemplate;
use App\Models\Communications\EmailTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * Seeds the system email templates from the original hardcoded Blade designs.
 * Idempotent — existing template keys are left untouched so admin edits survive
 * re-seeding.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(
            key: 'auth.verify_email',
            name: 'Email Verification',
            subject: 'Verify Your Email — {platform_name}',
            htmlBody: $this->layout(<<<'HTML'
                <p>Hello {first_name},</p>
                <p>Thank you for joining {platform_name}. Please verify your email address to activate your account.</p>
                <p><a href="{verification_url}" class="button">Verify Email Address</a></p>
                <p>This link expires in 24 hours. If you did not create an account, you can safely ignore this email.</p>
                <p>If the button above doesn't work, paste this URL into your browser:<br>
                <a href="{verification_url}">{verification_url}</a></p>
                HTML),
            textBody: <<<'TEXT'
                Hello {first_name},

                Thank you for joining {platform_name}. Please verify your email address to activate your account by visiting:

                {verification_url}

                This link expires in 24 hours. If you did not create an account, you can safely ignore this email.
                TEXT,
        );

        $this->seed(
            key: 'auth.password_reset',
            name: 'Password Reset',
            subject: 'Reset Your Password — {platform_name}',
            htmlBody: $this->layout(<<<'HTML'
                <p>Hello {first_name},</p>
                <p>We received a request to reset the password for your {platform_name} account. Click the button below to choose a new password.</p>
                <p><a href="{reset_url}" class="button">Reset Password</a></p>
                <p>This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email — your password will not change.</p>
                <p>If the button above doesn't work, paste this URL into your browser:<br>
                <a href="{reset_url}">{reset_url}</a></p>
                HTML),
            textBody: <<<'TEXT'
                Hello {first_name},

                We received a request to reset the password for your {platform_name} account. Choose a new password by visiting:

                {reset_url}

                This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email — your password will not change.
                TEXT,
        );

        $this->seed(
            key: 'auth.mfa_code',
            name: 'MFA Verification Code',
            subject: 'Your {platform_name} verification code',
            htmlBody: null,
            textBody: <<<'TEXT'
                Your verification code is: {code}

                This code expires in {expires_minutes} minutes.

                If you did not request this code, contact support immediately.
                TEXT,
        );

        $this->seed(
            key: 'auth.recovery_codes',
            name: 'New Recovery Codes',
            subject: 'Your new {platform_name} recovery codes',
            htmlBody: null,
            textBody: <<<'TEXT'
                An administrator has generated new recovery codes for your {platform_name} account.

                Your previous recovery codes are no longer valid. Save the codes below somewhere safe.
                Each code can only be used once to regain access if you lose your authenticator device.

                {codes}

                If you did not expect this email, contact support immediately.
                TEXT,
        );

        $this->seed(
            key: 'auth.mfa_enabled_by_admin',
            name: 'MFA Method Enabled by Admin',
            subject: 'A two-factor method was enabled on your {platform_name} account',
            htmlBody: null,
            textBody: <<<'TEXT'
                An administrator has enabled a two-factor authentication method on your {platform_name} account: {method_label}.

                The next time you sign in, you will be asked for this second factor in addition to your password.

                If this is the authenticator app and you no longer have {platform_name} set up in your authenticator, sign in using one of your recovery codes, then re-enroll from your Security settings. If you have also lost your recovery codes, contact support to regain access.

                If you did not expect this change, contact support immediately.
                TEXT,
        );

        $this->seed(
            key: 'application.message',
            name: 'Application Message Notification',
            subject: 'New message regarding your application — {platform_name}',
            htmlBody: $this->layout(<<<'HTML'
                <p>Hi {recipient_name},</p>
                <p><strong>{sender_role}</strong> has sent you a message regarding your lease application.</p>
                <blockquote style="margin: 0 0 20px; padding: 16px 20px; background: #f5f0eb; border-left: 3px solid #C5392A;">{message_body}</blockquote>
                <p><strong>You cannot reply directly to this email.</strong> To reply, log in to your account and view your application.</p>
                <p><a href="{login_url}" class="button">Log In &amp; Reply</a></p>
                <p style="font-size: 13px; color: #7a6552;">This message was sent because you have an active application on {platform_name}. Application ID: {application_ref}</p>
                HTML),
            textBody: <<<'TEXT'
                Hi {recipient_name},

                {sender_role} has sent you a message regarding your lease application:

                {message_body}

                You cannot reply directly to this email. To reply, log in to your account and view your application:

                {login_url}

                This message was sent because you have an active application on {platform_name}. Application ID: {application_ref}
                TEXT,
        );

        $this->seed(
            key: 'property.ownership_status',
            name: 'Property Ownership Review Status',
            subject: 'Proof of ownership {status_label}: {property_title}',
            htmlBody: $this->layout(<<<'HTML'
                <p>Hi {recipient_name},</p>
                <p>Here's an update on the proof of ownership you submitted for <strong>{property_title}</strong>.</p>
                <p style="margin: 0 0 20px;">
                    <span style="display: inline-block; background: #f5f0eb; border-left: 3px solid #C5392A; padding: 6px 14px; font-weight: bold; letter-spacing: 0.05em; text-transform: uppercase; font-size: 13px;">{status_label}</span>
                </p>
                <p>{status_message}</p>
                <p><a href="{property_url}" class="button">View Property Status</a></p>
                <p style="font-size: 13px; color: #7a6552;">You're receiving this because you submitted proof of ownership on {platform_name}. Questions? Contact us at {support_email}.</p>
                HTML),
            textBody: <<<'TEXT'
                Hi {recipient_name},

                Here's an update on the proof of ownership you submitted for {property_title}.

                STATUS: {status_label}

                {status_message}

                View your property status:
                {property_url}

                You're receiving this because you submitted proof of ownership on {platform_name}. Questions? Contact us at {support_email}.
                TEXT,
        );
    }

    private function seed(string $key, string $name, string $subject, ?string $htmlBody, ?string $textBody): void
    {
        $exists = EmailTemplate::on('communications')
            ->where('template_key', $key)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return;
        }

        $template = EmailTemplate::on('communications')->create([
            'template_key' => $key,
            'name'         => $name,
            'category'     => 'system',
        ]);

        EmailTemplateVersion::on('communications')->create([
            'template_id'    => $template->id,
            'version_number' => 1,
            'subject'        => $subject,
            'html_body'      => $htmlBody,
            'text_body'      => $textBody,
            'status'         => 'active',
            'notes'          => 'Initial version, migrated from hardcoded Blade view.',
        ]);
    }

    /** The shared HTML email shell used by the original Blade designs. */
    private function layout(string $bodyHtml): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                        <h1>{platform_name}</h1>
                    </div>
                    <div class="body">
            {$bodyHtml}
                    </div>
                    <div class="footer">
                        <p>© {current_year} {platform_name} · <a href="{app_url}/privacy">Privacy Policy</a> · <a href="{app_url}/terms">Terms of Service</a></p>
                    </div>
                </div>
            </body>
            </html>
            HTML;
    }
}
