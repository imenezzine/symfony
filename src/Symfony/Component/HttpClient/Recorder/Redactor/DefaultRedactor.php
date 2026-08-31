<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Recorder\Redactor;

/**
 * Masks known-sensitive headers, query-string parameters and body fields by default.
 */
final class DefaultRedactor implements RedactorInterface
{
    private const MASK = '[REDACTED]';

    private const DEFAULT_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
    ];

    private const DEFAULT_QUERY_PARAMS = [
        'token',
        'access_token',
        'api_key',
        'apikey',
        'secret',
        'password',
        'key',
        'client_secret',
    ];

    private const DEFAULT_BODY_FIELDS = [
        'password',
        'secret',
        'token',
        'access_token',
        'api_key',
        'client_secret',
        'authorization',
    ];

    /**
     * @param string[] $headerDenyList     header names to mask (case-insensitive), in addition to the defaults
     * @param string[] $queryParamDenyList query-string parameter names to mask (case-insensitive), in addition to the defaults
     * @param string[] $bodyFieldDenyList  top-level or nested JSON field names to mask (case-insensitive), in addition to the defaults
     */
    public function __construct(
        private array $headerDenyList = self::DEFAULT_HEADERS,
        private array $queryParamDenyList = self::DEFAULT_QUERY_PARAMS,
        private array $bodyFieldDenyList = self::DEFAULT_BODY_FIELDS,
    ) {
    }

    public function redactUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        foreach ($query as $name => $value) {
            if (\in_array(strtolower((string) $name), $this->queryParamDenyList, true)) {
                $query[$name] = self::MASK;
            }
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        }
        $rebuilt .= $parts['host'] ?? '';
        $rebuilt .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?'.http_build_query($query);
        $rebuilt .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $rebuilt;
    }

    public function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $values) {
            $values = (array) $values;
            $redacted[$name] = \in_array(strtolower((string) $name), $this->headerDenyList, true)
                ? array_fill(0, \count($values), self::MASK)
                : $values;
        }

        return $redacted;
    }

    public function redactBody(?string $body): ?string
    {
        if (null === $body || '' === $body) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || \JSON_ERROR_NONE !== json_last_error()) {
            return $body;
        }

        array_walk_recursive($decoded, function (&$value, $key): void {
            if (\is_string($key) && \in_array(strtolower($key), $this->bodyFieldDenyList, true)) {
                $value = self::MASK;
            }
        });

        return json_encode($decoded);
    }
}
