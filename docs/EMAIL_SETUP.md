# Email setup (staff invitations and password reset)

The app emails two things: the invitation a new staff member uses to choose a password, and the "Forgot your password?" link. Mail settings live only in `.env`; nothing is hardcoded and no secret belongs in git.

By default `MAIL_MAILER=log`, which writes emails to `storage/logs/laravel.log` instead of sending them. Everything still works: an administrator can set a **temporary password** from the Staff screen when email is not available.

## Gmail with an App Password (small deployments)

1. Sign in to the Google account that will send the mail.
2. Turn on **2-Step Verification** (Google Account → Security).
3. Open **App passwords** (Google Account → Security → 2-Step Verification → App passwords), create one named e.g. `SACCO Credit`, and copy the 16-character password. Remove spaces when you paste it.
4. Put these in the server's `.env` (never commit them):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_FROM_ADDRESS=your-address@gmail.com
MAIL_FROM_NAME="SACCO Credit"
```

5. Apply the change: `php artisan config:clear`, then restart PHP-FPM (or the web server process).
6. Add a staff member on a test address and check that the invitation arrives (also check spam the first time).

If sending fails, the account is still created. The administrator sees "Account created but the email could not be sent. Use Resend or set a temporary password." and the error is written to `storage/logs/laravel.log` (without the link).

## Limits and a better option for real clients

A normal Gmail account allows roughly **500 emails per day** and Google can block sign-ins from servers it does not recognise. That is fine for trying the system out. For real SACCO clients use a transactional email provider such as **Brevo, Mailgun, Amazon SES or Postmark**: set `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and `MAIL_PASSWORD` to the provider's SMTP values, and use a `MAIL_FROM_ADDRESS` on a domain you have verified with them (SPF/DKIM), which also keeps the emails out of spam.

## Optional: send in the background

Emails are sent straight away so a failure can be shown to the administrator. If you run a queue worker (`php artisan queue:work`) and prefer background sending, set `MAIL_QUEUE=true`. A failure then appears in the failed jobs list rather than on screen.

## Deployment checklist

1. Add the `MAIL_*` values to the server `.env`.
2. `php artisan migrate --force` (creates `staff_invitations`).
3. `php artisan config:clear`, then restart PHP-FPM.
4. Send one test invitation.
