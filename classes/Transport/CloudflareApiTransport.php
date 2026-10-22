<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Cloudflare Email Service's REST send endpoint as a Symfony Mailer transport.
 *
 * There is no Symfony bridge for Cloudflare, so this is one, written against
 * the parts of `AbstractApiTransport` that are the same from Mailer 5.4, which
 * the Email plugin ships, to 7.
 *
 * `POST /accounts/{account_id}/email/sending/send`, with an API token that has
 * Email Sending: Edit. Cloudflare answers with the recipients sorted into
 * `delivered`, `queued` and `permanent_bounces` rather than with a message id.
 * A send where every recipient bounced permanently is a failure here, so the
 * site sees it as one; a send where some got through is not.
 */
final class CloudflareApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.cloudflare.com';

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $accountId,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('cloudflare+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        if (trim($this->accountId) === '' || trim($this->token) === '') {
            throw new TransportException(
                'Unable to send an email: the Email Cloudflare plugin needs both an account ID and an API token.'
            );
        }

        $response = $this->client->request(
            'POST',
            'https://' . $this->getEndpoint() . '/client/v4/accounts/' . rawurlencode(trim($this->accountId)) . '/email/sending/send',
            [
                'json' => Payload::of($email, $envelope, $this->getRecipients($email, $envelope)),
                'headers' => ['Authorization' => 'Bearer ' . trim($this->token)],
            ]
        );

        $status = 0;
        try {
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(
                'Unable to send an email: ' . $response->getContent(false) . sprintf(' (code %d).', $status),
                $response
            );
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach Cloudflare.', $response, 0, $e);
        }

        if ($status !== 200 || ($body['success'] ?? false) !== true) {
            throw new HttpTransportException(
                'Unable to send an email: ' . self::errors($body) . sprintf(' (code %d).', $status),
                $response
            );
        }

        $result = \is_array($body['result'] ?? null) ? $body['result'] : [];
        $bounced = array_values(array_filter((array)($result['permanent_bounces'] ?? []), 'is_string'));
        $through = \count((array)($result['delivered'] ?? [])) + \count((array)($result['queued'] ?? []));

        if ($bounced !== [] && $through === 0) {
            throw new HttpTransportException(
                'Unable to send an email: Cloudflare reported a permanent bounce for ' . implode(', ', $bounced) . '.',
                $response
            );
        }

        return $response;
    }

    /**
     * Cloudflare's errors, as one sentence: `10102 email.sending.error.authentication.forbidden`.
     *
     * @param array<string, mixed> $body
     */
    public static function errors(array $body): string
    {
        $parts = [];
        foreach ((array)($body['errors'] ?? []) as $error) {
            if (\is_array($error)) {
                $parts[] = trim(($error['code'] ?? '') . ' ' . ($error['message'] ?? ''));
            }
        }

        return $parts === [] ? 'Cloudflare gave no reason' : implode('; ', $parts);
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST) . ($this->port ? ':' . $this->port : '');
    }
}
