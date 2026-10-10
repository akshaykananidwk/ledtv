# Email — SMTP, forgot password, invites, sign-up mails (2.8)

Every e-mail the platform sends — password reset links, invite links for new users, sign-up codes, the welcome
mail of a free trial, "TV offline / back online" alerts, invoices and payment reminders, auto-suspend notices,
marketplace codes — goes through one class, `core/Mailer.php`, in one branded layout (`core/MailTemplate.php`).

| Piece | Where |
|---|---|
| Transport: own SMTP client (STARTTLS / SSL, AUTH PLAIN / LOGIN) or PHP `mail()`; MIME (text + HTML), UTF-8 subjects, Message-ID, header-injection safe; mail log | `core/Mailer.php` |
| Branded layout: logo / name / colour (white-label aware: a customer's mails use the customer's / reseller's brand), button, big code, footer with the support contact; plain-text part always included | `core/MailTemplate.php` |
| Forgot password, reset / invite tokens, "password changed" mail | `core/PasswordReset.php`, `admin/forgot_password.php`, `admin/reset_password.php` |
| Settings card + test button + log | Super Admin console → System → Platform settings → **Email (SMTP)** (`admin/platform_settings.php?tab=email`) |
| Tables | migration `035_email.sql`: `password_resets`, `mail_log` |
| Translations | `lang/gu_email.php`, `lang/hi_email.php` |
| Tests | `tests/Integration/Apps/EmailFlowsTest.php`, fake SMTP server `tests/fixtures/fake_smtp.php`, browser QA `tests/browser/email_qa.js` |

## 1. Set it up on the live site (5 minutes)

1. Log in as Super Admin → **Platform settings → Email (SMTP)**.
2. **Send emails with: SMTP server (recommended).** Fill in the values of your mailbox (table below).
3. **Password**: the mailbox password, for Gmail an **App Password**. It is stored encrypted with `APP_KEY`
   (like the GitHub token of the updater) and is never shown again — leave the field empty to keep it.
4. **From email** = the same address as the username (Gmail, Zoho and most hosts refuse other senders).
   **From name** = what people see, default the platform name.
5. Type your own address under **Send a test email to** and press **Send test email**. It uses the values in the
   form (also before you save). If it fails, the exact error of the mail server is shown, with the SMTP
   conversation (passwords hidden). When it works, press **Save settings**.
6. Check that the test mail arrived in the **inbox**, not in spam (see §3 about SPF / DKIM).

| Provider | Server | Port · Encryption | Username / password |
|---|---|---|---|
| Gmail / Google Workspace | `smtp.gmail.com` | 587 · STARTTLS | your Gmail address · an **App Password** (below) |
| Hostinger | `smtp.hostinger.com` | 465 · SSL | full mailbox address from hPanel → Emails · its password |
| Zoho Mail | `smtp.zoho.in` (India accounts) or `smtp.zoho.com` | 465 · SSL or 587 · STARTTLS | your Zoho address · password, with 2FA an application-specific password |
| cPanel hosting | `mail.your-domain.com` | 465 · SSL | full address from cPanel → Email Accounts · its password |

### Gmail App Password (step by step)

1. Open <https://myaccount.google.com> with the Gmail account that should send the mails.
2. **Security → 2-Step Verification** — switch it on if it is off (App Passwords need it).
3. Back on Security, open **App passwords** (or search "App passwords" in the account settings).
4. Name it e.g. "Krishna Cloud TV" and press **Create**. Google shows a 16-letter password (`abcd efgh ijkl mnop`).
5. Paste it into **Password** in the Email (SMTP) card (spaces do not matter), username = the Gmail address.

Gmail sends about 500 mails per day from a normal account; for more use Google Workspace or your host's mailbox.

### Which errors mean what

| Error shown | Meaning / fix |
|---|---|
| `SMTP connect to host:port failed: Connection refused / timed out` | wrong server or port, or the hosting blocks outgoing SMTP on that port — try 465 SSL instead of 587 (or the other way round); some hosts only allow their own mail server |
| `SMTP server does not offer STARTTLS on port …` | choose **SSL / TLS (465)** for port 465, or the right port for STARTTLS |
| `SMTP STARTTLS handshake failed` / `TLS handshake / certificate problem` | the server certificate is not trusted (own server with a self-signed certificate → tick "Allow self-signed certificate", only for your own server) |
| `SMTP error at AUTH …: 535 …` | wrong username / password; Gmail: you need an App Password |
| `SMTP error at MAIL FROM: 553/550 …` | the From email is not allowed for this mailbox — use the username as From email |
| `SMTP error at RCPT TO: 550 …` | the recipient address does not exist / is refused |
| `PHP mail() failed …` | the hosting has no working `sendmail` — use SMTP |

## 2. What the platform sends

| E-mail | When | Language / brand |
|---|---|---|
| **Reset your password** (link, 60 min, single use) | Forgot password? on the login page | user's language; customer users get the customer's / reseller's brand and a branded login link |
| **Your password was changed** | after a reset, and after a password change in My profile | as above |
| **Your account is ready — set your password** (link, 72 h) | an admin creates a user with "Email an invite link" | as above |
| **Verification code** (6 digits, 15 min) | online sign-up (OTP mode) | language chosen in the form, platform brand |
| **Your free trial is ready** (welcome) | the trial account was created (OTP, auto or approved) — login link, email + username, trial days, how to connect the first TV | as above |
| Platform notice "new free trial / sign-up waiting / upgrade request" | to *signup_notify_email* or the platform admin email | platform |
| TV offline / back online, device health, staff alerts, guest requests | customer Settings → Notifications | customer brand |
| Invoice reminders, auto-suspend, trial reminders, plan active | Billing / trial tasks | platform |
| Marketplace codes and booking notices | Ad marketplace | platform |

Old plain-text notifications keep their text; they now also have the HTML layout.

## 3. Not landing in spam — SPF and DKIM in plain words

Mail providers check whether a server is *allowed* to send for your domain:

- **SPF** is one line in your domain's DNS that lists which servers may send mail for the domain. Your mail
  provider tells you the exact value (Hostinger: hPanel → Emails → DNS records; Zoho: Mail Admin → Domains;
  Google Workspace: `v=spf1 include:_spf.google.com ~all`). Only one SPF line per domain — merge them if you use two.
- **DKIM** is a digital signature: the provider gives you a DNS record (a long `TXT` value); add it once and every
  mail is signed.
- **DMARC** (optional, recommended): a DNS line such as `v=DMARC1; p=none; rua=mailto:you@your-domain` that tells
  receivers what to do with mails failing SPF / DKIM.

With a free Gmail address as sender you do not need DNS changes (Google signs the mails), but recipients see
"via gmail.com". A mailbox on your own domain (Hostinger, Zoho, Workspace) with SPF + DKIM looks most professional.
PHP `mail()` on shared hosting usually has neither — that is why those mails land in spam.

## 4. Forgot password — how it works

- The login page says **Email or username** and has **Forgot password?** (also on white-label logins `?b=<customer>`).
- The request takes an email address **or** a username. The answer is **always the same** ("If an account exists …")
  after a minimum time of 1.2 s, so nobody can find out which addresses have accounts.
- Limits: **5 requests per 15 minutes per IP** (then "Too many requests"), **3 e-mails per hour per account**
  (more requests stay silent). Inactive users, users of archived or demo customers, suspended resellers: no mail.
- The token is 32 random bytes; only its SHA-256 is stored (`password_resets`), single use, 60 minutes; a new
  request invalidates the older links. Tokens are never logged. Opening the link moves the token into the session
  and reloads the page without it; the page sends `Referrer-Policy: no-referrer`.
- Setting the new password uses the normal policy (8+ characters, letters and numbers, not the email / username),
  clears failed attempts and the lock, **logs the user out everywhere**, writes the activity log (platform level
  for platform users, the customer's log for customer users) and sends "Your password was changed".
- Works for every role: Super Admin, reseller, chain admin, customer admin / manager / staff / reception.
- Recovery when nobody can receive mail: `php bin/make_super_admin.php --email=you@example.com` (unchanged).

## 5. Invites instead of shared passwords

User forms (customer → Users, Super Admin → Customer 360 → Users, new customer's first admin, platform admins,
reseller logins, chain admins) have **"Email an invite link — the user chooses the password"** (ticked by default).
Leave the password empty: the account gets an unknown random password and the user receives a 72-hour link to
choose one (the same reset page, title "Set your password"). Untick it to set a password directly as before.

## 6. Mail log

Platform settings → Email (SMTP) → **Email log**: the last 200 sends (time, recipient, subject, transport,
sent / failed + the error). Bodies are never stored (they contain reset links); 6+-digit numbers in subjects
(sign-up codes) are masked. Failures are also written to `logs/mail.log`, password reset events to `logs/auth.log`.

## 7. Developers

```php
[$text, $html] = MailTemplate::render(['brand' => Branding::get($hotelId), 'lang' => 'gu', 'title' => '…',
    'paragraphs' => ['…'], 'button' => ['label' => '…', 'url' => '…'], 'small' => ['…']]);
Mailer::send($to, $subject, $text, $html);          // true / false + Mailer::$lastError
Notifier::email($to, $subject, $plainText);          // old API: wraps the text in the layout
```

Tests never send real mail: `Mailer::$testHook` (gets the whole message) or the old `Notifier::$mailer`; in a test
sandbox (`HC_TESTING`, a `hotelcast_sandbox_*` root or `config.php` `mail_outbox`) messages are appended to
`storage/mail_outbox.jsonl` and SMTP is only allowed to a loopback host (the fake server in the tests).
