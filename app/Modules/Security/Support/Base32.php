<?php

declare(strict_types=1);

namespace App\Modules\Security\Support;

use InvalidArgumentException;

/**
 * Base32 nach RFC 4648 (Alphabet A bis Z, 2 bis 7), wie von Authenticator-Apps erwartet.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $binary, bool $padding = false): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        if ($padding) {
            $encoded .= str_repeat('=', (8 - strlen($encoded) % 8) % 8);
        }

        return $encoded;
    }

    public static function decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(str_replace([' ', '-'], '', $encoded), '='));

        if ($encoded === '') {
            return '';
        }

        if (preg_match('/^[A-Z2-7]+$/', $encoded) !== 1) {
            throw new InvalidArgumentException('Ungültige Base32-Zeichenkette.');
        }

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr((int) bindec($byte));
            }
        }

        return $binary;
    }
}
