# Email Cloudflare Plugin

The **Email Cloudflare** plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It sends your site's mail through [Cloudflare Email Service](https://developers.cloudflare.com/email-service/), over Cloudflare's REST API or over SMTP.

It needs **PHP 8.1 or newer**, the [Email](https://github.com/getgrav/grav-plugin-email) plugin, a Cloudflare account on the **Workers Paid** plan, and a sending domain whose DNS is on Cloudflare.

## Transactional mail only

Cloudflare allows transactional mail: order confirmations, password resets, account notices, form notifications. It does not allow newsletters or other bulk mail.

So if your site also sends newsletters (with Mailroom or KahunaCart Newsletters, for example), set the Email plugin's **Newsletter Mail Engine** (Email 5.3.3 or later) to a provider that allows them, such as Resend or Amazon SES. Site mail then goes through Cloudflare and newsletters through the other provider. A newsletter add-on will not send a campaign through Cloudflare, and its Health screen says so.

## Installation

    bin/gpm install email-cloudflare

## Setting up Cloudflare

1. In the Cloudflare dashboard, open **Compute > Email Service > Email Sending** and press **Onboard Domain** for the domain you send from. Cloudflare adds the DNS records itself, on a `cf-bounce` subdomain: MX for bounces, SPF, and a DKIM key under the `cf-bounce` selector. Your existing SPF and DMARC records are left alone.
2. Create an API token: **My Profile > API Tokens > Create Token > Custom token**, with one permission, **Account > Email Sending > Edit**, on the account that owns the domain.
3. Copy the account ID from the account's overview page.

## Configuration

```yaml
enabled: true
transport: api
account_id:
api_token:
```

The **transport** is `api` (the faster of the two) or `smtp`, for a host that blocks outbound API calls but allows port 465. Both use the **api_token**; SMTP does not need the **account_id**.

Then point the Email plugin's engine at Cloudflare, with a `from:` address on the onboarded domain:

```yaml
from: orders@example.com
mailer:
  engine: cloudflare
```

## What gets sent

Cloudflare checks every header against an allowlist and refuses the whole message over one it does not accept. This plugin filters the headers instead, so an extra header never costs you an order confirmation:

- `X-` headers and the ones Cloudflare allows (threading, `List-*`, `Precedence`, `Auto-Submitted` and the rest) go through.
- The ones Cloudflare sets itself, such as `Message-ID`, `Date` and `Return-Path`, are left out.
- `List-Unsubscribe` keeps only its https and mailto addresses, and `List-Unsubscribe-Post` only goes with an https one, because Cloudflare refuses plain http there.

Attachments and inline images are sent base64 encoded, up to Cloudflare's 5 MiB limit for the whole message.

## Delivery reports

Cloudflare has no delivery webhooks. Its delivery, bounce and complaint events go to a Cloudflare Queue, so this plugin reports no deliveries to the site. Cloudflare stops sending to an address that hard bounced or complained on its own.

## License

MIT, see [LICENSE](LICENSE).
