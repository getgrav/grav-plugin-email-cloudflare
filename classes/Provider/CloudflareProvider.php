<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * What Cloudflare Email Service knows about itself, for the Email plugin's
 * provider contract.
 *
 * ## Transactional mail only
 *
 * Cloudflare allows order confirmations, password resets, notifications and
 * the like, and does not allow newsletters or other bulk mail. So the
 * capabilities say `transactionalOnly`, and a newsletter add-on that reads it
 * refuses to send a campaign through this transport rather than putting the
 * site's Cloudflare account at risk. The flag only exists on an Email plugin
 * new enough to have it; an older one gets the same capabilities without it.
 *
 * ## No delivery reports
 *
 * Cloudflare has no delivery webhooks. Its delivery, bounce and complaint
 * events go to a Cloudflare Queue, which only a Worker or a pull consumer can
 * read. Since the only thing on a Grav site that reads delivery reports is a
 * newsletter add-on, and newsletters cannot go through Cloudflare, there is
 * nothing for them to feed, so `reports()` and `setup()` are null. Cloudflare
 * still suppresses an address that bounced hard or complained, on its own.
 *
 * ## DNS
 *
 * Onboarding a domain in Cloudflare adds every record itself, on a
 * `cf-bounce` subdomain: MX for bounces, an SPF record that includes
 * `_spf.mx.cloudflare.net`, and a DKIM key under the `cf-bounce` selector. The
 * return path is that subdomain, which is what aligns SPF, so the apex record
 * does not need to mention Cloudflare.
 */
final class CloudflareProvider implements Provider
{
    public const ENGINE = 'cloudflare';

    public const SPF_INCLUDE = '_spf.mx.cloudflare.net';

    public const SELECTOR = 'cf-bounce';

    public const RETURN_PATH = 'cf-bounce';

    public const INSTRUCTIONS_KEY = 'PLUGIN_EMAIL_CLOUDFLARE.PROVIDER_INSTRUCTIONS';

    public const ECHO_NOTE_KEY = 'PLUGIN_EMAIL_CLOUDFLARE.ECHO_NOTE';

    /**
     * @param array<string, mixed>                              $config    this plugin's settings
     * @param (\Closure(string $key, string $english): string)|null $translate
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?\Closure $translate = null,
    ) {
    }

    /** @return list<string> */
    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function capabilities(): Capabilities
    {
        $args = [
            'customHeaders' => true,
            'unsubscribeHeaders' => true,
            'echoesHeaders' => false,
            'echoNote' => $this->say(
                self::ECHO_NOTE_KEY,
                'Cloudflare has no delivery webhooks. Its delivery events go to a Cloudflare Queue instead, '
                . 'so nothing on this site hears what happened to a message after it left. Cloudflare stops '
                . 'sending to an address that bounced or complained on its own.'
            ),
        ];

        if (property_exists(Capabilities::class, 'transactionalOnly')) {
            $args['transactionalOnly'] = true;
        }

        return new Capabilities(...$args);
    }

    public function reports(): ?DeliveryReports
    {
        return null;
    }

    public function setup(): ?WebhookSetup
    {
        return null;
    }

    public function domain(): DomainFacts
    {
        return new DomainFacts(
            spfInclude: self::SPF_INCLUDE,
            dkimZone: null,
            returnPathZone: null,
            lookup: static fn (string $domain): array => [
                'selectors' => [self::SELECTOR],
                'return_paths' => [self::RETURN_PATH . '.' . strtolower(trim($domain, '. '))],
            ],
        );
    }

    public function instructions(): string
    {
        return $this->say(
            self::INSTRUCTIONS_KEY,
            'Cloudflare has no delivery webhooks, so there is no address to register. In Cloudflare, open '
            . 'Compute, Email Service, Email Sending and press Onboard Domain for the domain you send from. '
            . 'Cloudflare adds the DNS records itself.'
        );
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    private function say(string $key, string $english): string
    {
        if ($this->translate === null) {
            return $english;
        }

        try {
            $said = ($this->translate)($key, $english);
        } catch (\Throwable) {
            return $english;
        }

        return !\is_string($said) || trim($said) === '' || $said === $key ? $english : $said;
    }
}
