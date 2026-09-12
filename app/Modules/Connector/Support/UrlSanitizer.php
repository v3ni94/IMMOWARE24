<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

use App\Core\Support\SecretMasker;

/**
 * Entfernt Userinfo und maskiert geheime Query-Parameter, bevor eine URL protokolliert wird.
 */
final class UrlSanitizer
{
    public function __construct(private readonly SecretMasker $masker) {}

    public function sanitize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $this->masker->maskString($url);
        }

        $result = '';

        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'].'://';
        }

        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $result .= ':'.$parts['port'];
        }

        $result .= $parts['path'] ?? '/';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $result .= '?'.$this->sanitizeQuery($parts['query']);
        }

        return $result;
    }

    private function sanitizeQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $key = (string) $key;

            if ($value === null) {
                $pairs[] = $key;

                continue;
            }

            $pairs[] = $key.'='.($this->masker->isSensitiveKey(urldecode($key)) ? SecretMasker::MASK : $this->masker->maskString($value));
        }

        return implode('&', $pairs);
    }
}
