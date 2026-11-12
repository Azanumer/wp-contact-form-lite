# WP Contact Form Lite

A dependency-free contact form for WordPress. Drop the shortcode on a page and it just works — no external service, no page builder, no JavaScript framework.

## Usage

Add the shortcode to any page or post:

```
[contact_form]
```

## Features

- **Shortcode form** — name, email, subject and message fields with server-side validation and value repopulation on error.
- **Spam traps, no CAPTCHA** — honeypot field, a minimum-time-to-submit check, and a WordPress nonce stop most automated spam without annoying real visitors.
- **Per-IP rate limiting** — configurable max submissions per IP per hour (default 5), stored in transients.
- **Optional submissions inbox** — every delivered message can also be saved as a private post under **Form Submissions** in the admin menu, so nothing is lost if email fails.
- **Settings page** — recipient email, subject prefix, success message, rate limit and timing threshold under **Settings → Contact Form Lite**.
- **Filter** — `wcfl_client_ip` lets you supply the real client IP when WordPress sits behind a proxy or Cloudflare.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Add `[contact_form]` to a page and set the recipient under **Settings → Contact Form Lite**.

## Uninstall

Deactivating keeps your settings. Deleting the plugin removes the settings option and all saved submissions.
