<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailCloudflare\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;

/**
 * A Symfony message as the JSON body Cloudflare's send endpoint takes.
 *
 * Kept apart from the transport so it can be read in a test without anything
 * leaving the machine.
 *
 * ## Headers
 *
 * Cloudflare checks every header against an allowlist and **rejects the whole
 * send** over one it does not accept, rather than dropping it. A Grav site adds
 * headers of its own (a Message-ID, an X-Mailer, the newsletter's send id), so
 * passing them all through would turn a harmless extra into a lost order
 * confirmation. So the headers are filtered here instead:
 *
 * - the ones Cloudflare sets itself (`Message-ID`, `Date`, `Return-Path` and
 *   the rest of {@see PLATFORM}) are left out, because Cloudflare refuses them;
 * - the ones that have their own field (`From`, `To`, `Subject`, ...) go in
 *   those fields;
 * - `X-` headers and the ones on {@see ALLOWED} go through, within
 *   Cloudflare's name and length rules;
 * - anything else is left out.
 *
 * `List-Unsubscribe` has one more rule: Cloudflare only takes https and mailto
 * addresses in it, and refuses `List-Unsubscribe-Post` without an https one.
 * A site on plain http (a local copy, say) would have its mail refused, so
 * those two are left out there instead.
 */
final class Payload
{
    /** Headers Cloudflare's infrastructure sets and refuses from a sender. */
    public const PLATFORM = [
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
        'dkim-signature', 'return-path', 'received', 'feedback-id', 'tls-required',
        'tls-report-domain', 'tls-report-submitter', 'cfbl-address', 'cfbl-feedback-id',
    ];

    /** Headers that are fields of their own in the request body. */
    public const FIELDS = ['from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender'];

    /** Non-`X-` headers Cloudflare accepts, from its email headers reference. */
    public const ALLOWED = [
        'in-reply-to', 'references', 'thread-index', 'thread-topic',
        'list-unsubscribe', 'list-unsubscribe-post', 'list-id', 'list-archive', 'list-help',
        'list-owner', 'list-post', 'list-subscribe', 'precedence', 'auto-submitted',
        'content-language', 'keywords', 'comments', 'importance', 'priority', 'sensitivity',
        'organization', 'require-recipient-valid-since', 'expires', 'reply-by', 'archived-at',
    ];

    /** Headers whose value Cloudflare checks against a short list. */
    public const VALUES = [
        'precedence' => ['bulk', 'list', 'junk'],
        'auto-submitted' => ['auto-generated', 'auto-replied', 'auto-notified'],
        'importance' => ['high', 'normal', 'low'],
        'priority' => ['normal', 'non-urgent', 'urgent'],
        'sensitivity' => ['personal', 'private', 'company-confidential'],
    ];

    /** Cloudflare's limit on one header value, in bytes. */
    public const MAX_VALUE = 2048;

    /**
     * @param list<Address> $recipients the envelope's recipients, less cc and bcc
     * @return array<string, mixed>
     */
    public static function of(Email $email, Envelope $envelope, array $recipients): array
    {
        $from = $email->getFrom()[0] ?? $envelope->getSender();

        $payload = [
            'from' => self::address($envelope->getSender(), $from->getName()),
            'to' => array_map(static fn (Address $a): string|array => self::address($a, $a->getName()), $recipients),
            'subject' => (string)$email->getSubject(),
        ];

        if ($cc = $email->getCc()) {
            $payload['cc'] = array_map(static fn (Address $a): string|array => self::address($a, $a->getName()), $cc);
        }
        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = array_map(static fn (Address $a): string|array => self::address($a, $a->getName()), $bcc);
        }
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = self::address($replyTo[0], $replyTo[0]->getName());
        }

        $text = $email->getTextBody();
        if (\is_resource($text)) {
            $text = stream_get_contents($text);
        }
        if (\is_string($text) && $text !== '') {
            $payload['text'] = $text;
        }

        $html = $email->getHtmlBody();
        if (\is_resource($html)) {
            $html = stream_get_contents($html);
        }
        if (\is_string($html) && $html !== '') {
            $payload['html'] = $html;
        }

        $attachments = self::attachments($email);
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $headers = self::headers($email->getHeaders());
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /**
     * The headers Cloudflare will take, as a name to value map.
     *
     * @return array<string, string>
     */
    public static function headers(Headers $headers): array
    {
        $out = [];

        foreach ($headers->all() as $header) {
            $name = $header->getName();
            $lower = strtolower($name);
            $value = trim($header->getBodyAsString());

            if (\in_array($lower, self::PLATFORM, true) || \in_array($lower, self::FIELDS, true)
                || str_starts_with($lower, 'arc-') || $value === '' || \strlen($value) > self::MAX_VALUE) {
                continue;
            }

            $custom = preg_match('/^X-[A-Za-z0-9\-_]+$/i', $name) === 1 && \strlen($name) <= 100;
            if (!$custom && !\in_array($lower, self::ALLOWED, true)) {
                continue;
            }

            if (isset(self::VALUES[$lower]) && !\in_array(strtolower($value), self::VALUES[$lower], true)) {
                continue;
            }

            $out[$name] = $value;
        }

        return self::unsubscribe($out);
    }

    /**
     * `List-Unsubscribe` with only the addresses Cloudflare accepts, and
     * `List-Unsubscribe-Post` only beside an https one.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function unsubscribe(array $headers): array
    {
        $listKey = self::keyOf($headers, 'list-unsubscribe');
        $postKey = self::keyOf($headers, 'list-unsubscribe-post');

        $https = false;
        if ($listKey !== null) {
            preg_match_all('/<([^>]+)>/', $headers[$listKey], $m);
            $kept = array_values(array_filter(
                $m[1],
                static fn (string $uri): bool => str_starts_with(strtolower($uri), 'https://')
                    || str_starts_with(strtolower($uri), 'mailto:')
            ));
            $https = (bool)array_filter($kept, static fn (string $uri): bool => str_starts_with(strtolower($uri), 'https://'));

            if ($kept === []) {
                unset($headers[$listKey]);
            } else {
                $headers[$listKey] = implode(', ', array_map(static fn (string $uri): string => '<' . $uri . '>', $kept));
            }
        }

        if ($postKey !== null && (!$https || $headers[$postKey] !== 'List-Unsubscribe=One-Click')) {
            unset($headers[$postKey]);
        }

        return $headers;
    }

    /**
     * Attachments and inline images, base64 encoded.
     *
     * @return list<array<string, string>>
     */
    private static function attachments(Email $email): array
    {
        $out = [];

        foreach ($email->getAttachments() as $part) {
            $headers = $part->getPreparedHeaders();
            $disposition = strtolower((string)$headers->getHeaderBody('Content-Disposition')) === 'inline' ? 'inline' : 'attachment';

            $row = [
                'content' => base64_encode($part->getBody()),
                'filename' => (string)($headers->getHeaderParameter('Content-Disposition', 'filename') ?? 'attachment'),
                'type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'disposition' => $disposition,
            ];

            if ($disposition === 'inline') {
                $row['content_id'] = $part->getContentId();
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * A plain address, or `{address, name}` where there is a name. The REST
     * API spells the key `address`, where the Workers binding says `email`.
     *
     * @return string|array{address: string, name: string}
     */
    private static function address(Address $address, string $name): string|array
    {
        return $name === '' ? $address->getAddress() : ['address' => $address->getAddress(), 'name' => $name];
    }

    /** @param array<string, string> $headers */
    private static function keyOf(array $headers, string $lower): ?string
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower($name) === $lower) {
                return $name;
            }
        }

        return null;
    }
}
