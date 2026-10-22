<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Tests\Unit;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A response with a status and a body written down in advance, which is
 * everything the transport reads.
 */
final class FixedResponse implements ResponseInterface
{
    /** @param array<string, mixed> $body */
    public function __construct(private readonly array $body, private readonly int $status = 200)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['application/json']];
    }

    public function getContent(bool $throw = true): string
    {
        return (string)json_encode($this->body);
    }

    public function toArray(bool $throw = true): array
    {
        return $this->body;
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = ['http_code' => $this->status, 'url' => 'https://api.cloudflare.com/client/v4/accounts/x/email/sending/send'];

        return $type === null ? $info : ($info[$type] ?? null);
    }
}
