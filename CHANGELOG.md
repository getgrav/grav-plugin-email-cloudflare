# v1.0.0
## 10/21/2026

1. [](#new)
    * Initial release. Sends the site's mail through Cloudflare Email Service, over the REST API or SMTP, with an API token that has Email Sending: Edit
    * Headers are filtered to what Cloudflare accepts, so a header it would refuse is left out rather than losing the message; `List-Unsubscribe` keeps its https and mailto addresses
    * Attachments and inline images, named recipients, cc, bcc and reply-to
    * A send where every recipient bounced permanently is reported as a failure
    * The provider tells the Email plugin that Cloudflare is for transactional mail only (Email 5.3.3), so newsletter add-ons will not send campaigns through it, and that it has no delivery webhooks
    * DNS facts for Health checks: SPF through `_spf.mx.cloudflare.net` on the `cf-bounce` return path, DKIM under the `cf-bounce` selector
