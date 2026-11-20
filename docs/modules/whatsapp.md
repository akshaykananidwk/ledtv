# WhatsApp messages to customers (2.10.4)

Super Admin console → **Insight → WhatsApp messages** (`admin/platform_whatsapp.php`, permission `platform.manage`,
platform admins only). Code: `core/WhatsApp.php`.

* **WhatsApp API (right card):** API URL (default `https://bulk.akdwk.in/api.php`), API key, session ID (the
  WhatsApp number of the device linked in the provider panel). Stored as platform settings `whatsapp_api_url`,
  `whatsapp_api_key`, `whatsapp_session_id`. The key is a password field: it is never printed back; leaving it
  empty keeps the saved one. The URL must be https.
* **New message:** text (up to 4,000 characters) with `:name` (contact person) and `:business` (customer name),
  optional photo / video / PDF link (`media_url`), recipients: active customers, customers on trial, all
  customers (suspended included) or selected customers. Archived customers never. Numbers come from the
  customer's *contact phone*; 10-digit Indian mobiles get `91`, `0` / `00` prefixes are removed, invalid
  numbers are skipped (the page counts them), one message per number, at most 1,000 per send.
* **Send test** sends the message to one number first. **Send to customers** asks for confirmation, sends one
  request per customer (POST JSON `{api_key, number, message, session_id, media_url?}`, 15 s timeout) and shows
  sent / failed with the provider's error per failed customer (number masked). A provider answer with
  `status: error|failed` or `success: false` counts as failed.
* **History:** last 30 sends (time, sent, failed, first 120 characters) and an audit-log entry
  `platform_whatsapp_send`; settings changes log `platform_whatsapp_settings` (without the key).

Tests: `tests/Integration/Apps/WhatsAppTest.php`.
