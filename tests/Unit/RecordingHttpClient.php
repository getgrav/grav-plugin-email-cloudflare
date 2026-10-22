<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Tests\Unit;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A Symfony HTTP client that writes the request down and answers a fixed
 * success, so that {@see \Grav\Plugin\EmailCloudflare\Transport\CloudflareApiTransport}
 * can be sent through without anything leaving the machine.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $options = [];

    public string $url = '';

    /** @param array<string, mixed> $answer */
    public function __construct(
        private readonly array $answer = ['success' => true, 'errors' => [], 'result' => ['delivered' => ['jane@example.com'], 'permanent_bounces' => [], 'queued' => []]],
        private readonly int $status = 200,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->url = $url;
        $this->options = $options;

        return new FixedResponse($this->answer, $this->status);
    }

    /** @return array<string, mixed> the JSON body the transport built */
    public function lastJson(): array
    {
        return \is_array($this->options['json'] ?? null) ? $this->options['json'] : [];
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \BadMethodCallException('nothing here streams');
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
