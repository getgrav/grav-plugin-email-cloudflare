<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Tests\Unit;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\EmailCloudflare\Provider\CloudflareProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function testItIsTheCloudflareEngine(): void
    {
        $provider = new CloudflareProvider();

        self::assertSame(['cloudflare'], $provider->engines());
        self::assertSame('cloudflare', $provider->key());
        self::assertSame('Cloudflare', $provider->label());
    }

    public function testHeadersGoOutAndNoEventsComeBack(): void
    {
        $provider = new CloudflareProvider();
        $capabilities = $provider->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders);
        self::assertStringContainsString('no delivery webhooks', $capabilities->echoNote);
        self::assertNull($provider->reports());
        self::assertNull($provider->setup());
    }

    public function testItSaysTransactionalOnlyWhereTheContractCanSayIt(): void
    {
        $capabilities = (new CloudflareProvider())->capabilities();

        if (!property_exists(Capabilities::class, 'transactionalOnly')) {
            self::markTestSkipped('this Email plugin has no transactionalOnly capability yet');
        }

        self::assertTrue($capabilities->transactionalOnly);
    }

    public function testTheDomainIsAlignedThroughTheCfBounceSubdomain(): void
    {
        $facts = (new CloudflareProvider())->domain();

        self::assertSame('_spf.mx.cloudflare.net', $facts->spfInclude);
        self::assertNull($facts->dkimZone);
        self::assertSame(
            ['selectors' => ['cf-bounce'], 'return_paths' => ['cf-bounce.example.com']],
            $facts->ask('Example.com.')
        );
    }

    public function testTheWordsCanBeTranslatedAndFallBackToEnglish(): void
    {
        $english = (new CloudflareProvider())->instructions();
        self::assertStringContainsString('Onboard Domain', $english);

        $translated = (new CloudflareProvider([], static fn (string $key, string $en): string => 'Übersetzt'))->instructions();
        self::assertSame('Übersetzt', $translated);

        $missing = (new CloudflareProvider([], static fn (string $key, string $en): string => $key))->instructions();
        self::assertSame($english, $missing);
    }
}
