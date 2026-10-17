# Email: letting the site send password resets and approval notices

The site cannot send email on its own. It borrows a mailbox your organization
already owns - a Gmail address, an Outlook mailbox, your provider's mail - and
sends *through* it. This page tells whoever owns that mailbox how to fill in
**Console -> Organization settings -> Email**.

Until this is done, two things do not work: **password resets** (no link can be
sent) and **"your account is approved" notices** (approve someone and they hear
nothing until you tell them another way). Everything else - announcements,
dues, documents, the directory - works without email.

## What to fill in

| Field | What it is | Example |
|---|---|---|
| Send mail as | The address members see and reply to | `treasurer@sunset-hills-hoa.org` |
| Mail server | The provider's outgoing (SMTP) host, below | `smtp.gmail.com` |
| Port | 587 for STARTTLS (usual), 465 for SSL | `587` |
| Connection | Must match the port | STARTTLS for 587, SSL for 465 |
| Username | Usually the full address of the sending mailbox | `treasurer@sunset-hills-hoa.org` |
| Password | The mailbox password - or, more often, an **app password** (below) | stored, never shown again |

Leave the password blank when saving to keep the one already stored. Clearing
both the From address and the server switches email off again.

After saving, type your own address into **Send a test to** and click **Send
test email**. Check the inbox *and* spam in a minute. If nothing arrives, the
notice on the page says which step failed - wrong host, wrong password, wrong
port/connection pair, in that order of likelihood.

## Settings by provider

Pick the row for wherever the sending mailbox lives. When in doubt, the
provider's own help page (linked) wins over this table.

### Gmail and Google Workspace

- Server: `smtp.gmail.com`, port **587** with **STARTTLS**, or port **465** with **SSL**.
- Username: the full Gmail / Workspace address.
- Password: **not** the login password. Turn on 2-Step Verification, then create
  an **app password** and paste that in.
  Where: Google Account -> Security -> 2-Step Verification -> App passwords.
  Help: <https://support.google.com/accounts/answer/185833>
- Workspace organizations can also use a relay (`smtp-relay.gmail.com`), but
  that is an administrator setup with IP allowlisting - the mailbox settings
  above are the right starting point for a club.
  Help: <https://knowledge.workspace.google.com/admin/gmail/send-email-from-a-printer-scanner-or-app>

### Outlook.com and Microsoft 365

- Outlook.com (personal): server `smtp-mail.outlook.com`, port **587** with
  **STARTTLS**. Username is the full address.
  Help: <https://support.microsoft.com/en-us/outlook/pop-imap-and-smtp-settings-for-outlook-com>
- Microsoft 365 (organization mailbox): server `smtp.office365.com`, port
  **587** with **STARTTLS**. **Port 465 is not supported.** The mailbox must be
  licensed, and **SMTP AUTH must be enabled** for the organization/mailbox - it
  is off by default in many tenants, and Security Defaults can block it.
  Your IT person flips that switch; there is nothing to fix on the site side.
  Help: <https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365>

### Yahoo Mail

- Server `smtp.mail.yahoo.com`, port **465** with **SSL** or **587** with **TLS**.
- Username is the full Yahoo address; the password is a **Yahoo app password**
  (Account security -> Generate app password).
  Help: <https://help.yahoo.com/kb/SLN4724.html>

### iCloud Mail

- Server `smtp.mail.me.com`, port **587** (SSL required - try STARTTLS first).
  Port 465 is not listed by Apple.
- Username is the full iCloud address; the password is an **app-specific
  password** (Apple Account with two-factor authentication required).
  Help: <https://support.apple.com/en-us/102525> and
  <https://support.apple.com/en-us/102654>

### Your web host or internet provider (cPanel and the like)

- The host names the outgoing server - often `mail.yourdomain.org` or the
  server's own hostname. Look in the host's control panel under Email, or ask
  their support for "SMTP settings for a mail client".
- Usual shape: port **465** with **SSL**, or **587** - but on some hosts 587 is
  unencrypted, so copy exactly what the host prints.
  Reference: <https://docs.cpanel.net/cpanel/meridian/email/connecting-email-to-a-mail-client-or-device/>

## Where do I find these details?

1. **Whose mailbox?** Any mailbox the organization controls and can keep. A
   shared `treasurer@...` or `info@...` beats someone's personal inbox, because
   people leave and passwords change.
2. **The password question:** if the provider offers "app passwords", use one
   and keep the real login private. The site stores what you paste and never
   shows it again - the field reads "a password is saved".
3. **Microsoft 365 not working with the right password?** Ask whoever manages
   your Microsoft tenant to check that SMTP AUTH is enabled for that mailbox.
   This is the single most common 365 failure and looks like a wrong password.
4. **Still stuck?** The test button's message names the failing step. Write it
   down verbatim, plus the provider and port you used, and send it to whoever
   set the server up.

## Safety notes for the technically curious

- Passwords live in the site's config table alongside everything else the
  console stores - readable by whoever can read the database, never echoed to
  the browser. Use a dedicated sending mailbox or an app password so a leak
  cannot become a full account takeover.
- Port and connection must agree: 587 goes with STARTTLS, 465 with SSL, 25
  with plain (local relay only). A mismatch is the most common "it just won't
  connect" cause and the error message says so.
- Direct sending from the server itself (its own mail server, port 25 to the
  world) is deliberately unsupported: a fresh office IP has no reputation and
  providers junk or reject it. The relay above is the supported path.
