<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Tests\Unit;

use Grav\Plugin\EmailCloudflare\Transport\Payload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The request body. Cloudflare refuses the whole send over one header it does
 * not accept, so most of what is checked here is what is left out.
 */
final class PayloadTest extends TestCase
{
    public function testHeadersCloudflareSetsOrRefusesAreLeftOut(): void
    {
        $email = self::email();
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', 'abc@example.com');
        $headers->addDateHeader('Date', new \DateTimeImmutable());
        $headers->addPathHeader('Return-Path', 'bounce@example.com');
        $headers->addTextHeader('X-Grav-Send-Id', '41');
        $headers->addTextHeader('X-Mailer', 'Grav');
        $headers->addTextHeader('Organization', 'Example Store');
        $headers->addTextHeader('Some-Unknown-Header', 'nope');
        $headers->addTextHeader('Precedence', 'first-class');
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');

        $out = self::payload($email)['headers'];

        self::assertSame([
            'X-Grav-Send-Id' => '41',
            'X-Mailer' => 'Grav',
            'Organization' => 'Example Store',
            'Auto-Submitted' => 'auto-generated',
        ], $out);
    }

    public function testOneClickUnsubscribeGoesThroughOverHttps(): void
    {
        $email = self::email();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/u/abc>, <mailto:u@example.com>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $out = self::payload($email)['headers'];

        self::assertSame('<https://example.com/u/abc>, <mailto:u@example.com>', $out['List-Unsubscribe']);
        self::assertSame('List-Unsubscribe=One-Click', $out['List-Unsubscribe-Post']);
    }

    public function testAPlainHttpUnsubscribeIsDroppedRatherThanLosingTheMessage(): void
    {
        $email = self::email();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<http://shop.test/u/abc>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        self::assertArrayNotHasKey('headers', self::payload($email));

        $mailto = self::email();
        $mailto->getHeaders()->addTextHeader('List-Unsubscribe', '<http://shop.test/u/abc>, <mailto:u@example.com>');
        $mailto->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        self::assertSame(['List-Unsubscribe' => '<mailto:u@example.com>'], self::payload($mailto)['headers']);
    }

    public function testRecipientsReplyToAndNamesUseTheRestSpelling(): void
    {
        $email = self::email()
            ->addCc(new Address('boss@example.com', 'The Boss'))
            ->addBcc('archive@example.com')
            ->replyTo(new Address('help@example.com', 'Help'));

        $json = self::payload($email, [new Address('jane@example.com', 'Jane Doe')]);

        self::assertSame([['address' => 'jane@example.com', 'name' => 'Jane Doe']], $json['to']);
        self::assertSame([['address' => 'boss@example.com', 'name' => 'The Boss']], $json['cc']);
        self::assertSame(['archive@example.com'], $json['bcc']);
        self::assertSame(['address' => 'help@example.com', 'name' => 'Help'], $json['reply_to']);
    }

    public function testAttachmentsAndInlineImagesAreBase64(): void
    {
        $email = self::email()
            ->attach('%PDF-1.4 fake', 'invoice.pdf', 'application/pdf')
            ->embed('PNGDATA', 'logo.png', 'image/png');

        $attachments = self::payload($email)['attachments'];

        self::assertSame([
            'content' => base64_encode('%PDF-1.4 fake'),
            'filename' => 'invoice.pdf',
            'type' => 'application/pdf',
            'disposition' => 'attachment',
        ], $attachments[0]);
        self::assertSame('inline', $attachments[1]['disposition']);
        self::assertSame(base64_encode('PNGDATA'), $attachments[1]['content']);
        self::assertNotSame('', $attachments[1]['content_id']);
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

    /**
     * @param list<Address>|null $to
     * @return array<string, mixed>
     */
    private static function payload(Email $email, ?array $to = null): array
    {
        $envelope = new Envelope(new Address('orders@example.com'), $to ?? [new Address('jane@example.com')]);

        return Payload::of($email, $envelope, $to ?? [new Address('jane@example.com')]);
    }
}
