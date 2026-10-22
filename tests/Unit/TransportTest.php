<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Tests\Unit;

use Grav\Plugin\EmailCloudflare\Transport\CloudflareApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The REST transport, against the Symfony Mailer 5.4 the Email plugin ships,
 * through an HTTP client that records the request instead of making it.
 */
final class TransportTest extends TestCase
{
    public function testItPostsToTheAccountsSendEndpointWithTheToken(): void
    {
        $client = new RecordingHttpClient();
        (new CloudflareApiTransport('a-token', 'acct123', $client))->send(self::email());

        self::assertSame('https://api.cloudflare.com/client/v4/accounts/acct123/email/sending/send', $client->url);
        self::assertSame('Bearer a-token', $client->options['headers']['Authorization'] ?? null);

        $json = $client->lastJson();
        self::assertSame(['address' => 'orders@example.com', 'name' => 'Example Store'], $json['from']);
        self::assertSame(['jane@example.com'], $json['to']);
        self::assertSame('Your order', $json['subject']);
        self::assertSame('Thanks', $json['text']);
        self::assertSame('<p>Thanks</p>', $json['html']);
    }

    public function testCloudflaresOwnErrorIsTheExceptionsMessage(): void
    {
        $client = new RecordingHttpClient([
            'success' => false,
            'errors' => [['code' => 10102, 'message' => 'email.sending.error.authentication.forbidden']],
            'result' => null,
        ], 403);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('10102 email.sending.error.authentication.forbidden');

        (new CloudflareApiTransport('a-token', 'acct123', $client))->send(self::email());
    }

    public function testASendEveryRecipientBouncedIsAFailure(): void
    {
        $client = new RecordingHttpClient([
            'success' => true,
            'errors' => [],
            'result' => ['delivered' => [], 'permanent_bounces' => ['jane@example.com'], 'queued' => []],
        ]);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('permanent bounce for jane@example.com');

        (new CloudflareApiTransport('a-token', 'acct123', $client))->send(self::email());
    }

    public function testAQueuedSendIsASend(): void
    {
        $client = new RecordingHttpClient([
            'success' => true,
            'errors' => [],
            'result' => ['delivered' => [], 'permanent_bounces' => [], 'queued' => ['jane@example.com']],
        ]);

        self::assertNotNull((new CloudflareApiTransport('a-token', 'acct123', $client))->send(self::email()));
    }

    public function testMissingSettingsSayWhichBeforeAnythingIsSent(): void
    {
        $client = new RecordingHttpClient();

        try {
            (new CloudflareApiTransport('a-token', '', $client))->send(self::email());
            self::fail('no exception');
        } catch (TransportException $e) {
            self::assertStringContainsString('account ID and an API token', $e->getMessage());
        }

        self::assertSame('', $client->url, 'nothing was posted');
    }

    private static function email(): Email
    {
        return (new Email())
            ->from(new Address('orders@example.com', 'Example Store'))
            ->to(new Address('jane@example.com'))
            ->subject('Your order')
            ->text('Thanks')
            ->html('<p>Thanks</p>');
    }
}
