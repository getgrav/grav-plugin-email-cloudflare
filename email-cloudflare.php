<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\EmailCloudflare\Provider\CloudflareProvider;
use Grav\Plugin\EmailCloudflare\Transport\CloudflareApiTransport;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Cloudflare Email Service for Grav's Email plugin.
 *
 * Two halves, like every `email-<provider>` plugin. The transport registers the
 * `cloudflare` engine and hands the Email plugin something that sends: the REST
 * API, or Cloudflare's SMTP endpoint for a host that blocks outbound API
 * calls. The provider tells anything that asks what Cloudflare does: custom
 * headers go out, delivery events do not come back, and only transactional
 * mail is allowed.
 *
 * Both need an API token with Email Sending: Edit on the account that owns the
 * sending domain. The API also needs the account's ID; SMTP does not.
 */
class EmailCloudflarePlugin extends Plugin
{
    /** The engine name this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'cloudflare';

    /** Cloudflare's SMTP endpoint, implicit TLS. */
    public const SMTP_HOST = 'smtp.mx.cloudflare.net';

    public const SMTP_PORT = 465;

    /** The SMTP user name is this literal word for everybody; the token is the password. */
    public const SMTP_USER = 'api_token';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines' => ['onEmailEngines', 0],
            'onEmailTransportDsn' => ['onEmailTransportDsn', 0],
            'onEmailProviders' => ['onEmailProviders', 0],
        ];
    }

    /** Composer autoload. */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e): void
    {
        $engines = $e['engines'];
        $engines->cloudflare = 'Cloudflare';
    }

    /** Hand the Email plugin a transport for the `cloudflare` engine. */
    public function onEmailTransportDsn(Event $e): void
    {
        if ($e['engine'] !== self::ENGINE) {
            return;
        }

        $options = (array)$this->config->get('plugins.email-cloudflare', []);
        $token = trim((string)($options['api_token'] ?? ''));

        if (($options['transport'] ?? 'api') === 'smtp') {
            $transport = new EsmtpTransport(self::SMTP_HOST, self::SMTP_PORT, true);
            $transport->setUsername(self::SMTP_USER);
            $transport->setPassword($token);
            $e['dsn'] = $transport;
        } else {
            $e['dsn'] = new CloudflareApiTransport($token, trim((string)($options['account_id'] ?? '')));
        }

        $e->stopPropagation();
    }

    /**
     * Tell the Email plugin what Cloudflare knows about itself.
     *
     * The event only exists on an Email plugin new enough to own the provider
     * contract. An older one never fires it and this plugin sends exactly as it
     * would have.
     */
    public function onEmailProviders(Event $e): void
    {
        $registry = $e['providers'] ?? null;
        if ($registry === null) {
            return;
        }

        $registry->add(new CloudflareProvider(
            (array)$this->config->get('plugins.email-cloudflare', []),
            fn (string $key, string $fallback): string => $this->say($key, $fallback),
        ));
    }

    /**
     * One language string, falling back to the English written in the provider.
     *
     * Grav answers with the key itself when nothing has translated it, so that
     * is what "no translation" looks like here.
     */
    protected function say(string $key, string $fallback): string
    {
        try {
            $said = Grav::instance()['language']->translate([$key]);
        } catch (\Throwable) {
            return $fallback;
        }

        return !\is_string($said) || $said === '' || $said === $key ? $fallback : $said;
    }
}
