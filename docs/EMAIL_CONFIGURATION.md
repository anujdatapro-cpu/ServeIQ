# Email Provider Configuration

Email settings are loaded by `config/load_environment.php`. Keep real API keys and SMTP credentials in the ignored local `.env` file or the deployment environment; never put them in `.env.example`.

## Provider selection

`MAIL_PROVIDER` is the primary selector and supports `smtp`, `resend`, `brevo`, and `development_preview`. `MAIL_TRANSPORT` is a backward-compatible fallback used only when `MAIL_PROVIDER` is empty or unset. An explicitly selected provider is not silently replaced if its credentials are missing; delivery fails with a generic user-facing error.

`development_preview` is selected only when `APP_ENV=development`. It does not send email; the generated OTP is shown on the verification page. Do not use this mode in production.

## Sender settings

A non-empty `MAIL_FROM` overrides the provider-specific sender. Leave it empty when using provider-specific values:

| Provider | Required credential | Sender setting |
|---|---|---|
| Brevo | `BREVO_API_KEY` | `BREVO_FROM_EMAIL` |
| Resend | `RESEND_API_KEY` | `RESEND_FROM_EMAIL` |
| SMTP | SMTP host and any required credentials | `SMTP_FROM_EMAIL` |

For Brevo and Resend, configure a sender address accepted by the provider. Resend's built-in testing sender may only deliver to the account owner's address. SMTP supports `SMTP_ENCRYPTION=tls` or `ssl` and uses `SMTP_FROM_NAME` for the display name.

## Local checks

Run `D:\xampp\php\php.exe services\email_security_test.php` from the repository root. This test checks selection and missing-credential behavior; it does not send email. Live delivery must be verified separately with an authorized test inbox and a configured provider.