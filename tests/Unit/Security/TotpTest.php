<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Modules\Security\Services\Totp;
use App\Modules\Security\Support\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 Anhang B, Secret "12345678901234567890", SHA1. */
    private const string RFC_SECRET_ASCII = '12345678901234567890';

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        // 8-stellige Referenzwerte: 94287082, 07081804, 14050471, 89005924, 69279037, 65353130
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_rfc6238_sha1_vectors_eight_digits(int $timestamp, string $expected): void
    {
        $totp = new Totp(period: 30, digits: 8, algorithm: 'sha1');

        $this->assertSame($expected, $totp->code(Base32::encode(self::RFC_SECRET_ASCII), $timestamp));
    }

    #[DataProvider('rfcVectors')]
    public function test_rfc6238_sha1_vectors_six_digits(int $timestamp, string $expected): void
    {
        $totp = new Totp(period: 30, digits: 6, algorithm: 'sha1');

        $this->assertSame(substr($expected, -6), $totp->code(Base32::encode(self::RFC_SECRET_ASCII), $timestamp));
    }

    public function test_verify_accepts_neighbouring_windows_and_rejects_further(): void
    {
        $totp = new Totp(window: 1);
        $secret = Base32::encode(self::RFC_SECRET_ASCII);
        $now = 1111111111;

        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now), $now));
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now - 30), $now));
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now + 30), $now));
        $this->assertFalse($totp->verify($secret, $totp->code($secret, $now - 60), $now));
        $this->assertFalse($totp->verify($secret, $totp->code($secret, $now + 60), $now));
        $this->assertFalse($totp->verify($secret, 'abcdef', $now));
        $this->assertFalse($totp->verify($secret, '12345', $now));
    }

    public function test_base32_roundtrip_and_known_value(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Base32::encode(self::RFC_SECRET_ASCII));
        $this->assertSame(self::RFC_SECRET_ASCII, Base32::decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'));
        $this->assertSame('MZXW6YTBOI======', Base32::encode('foobar', true));
        $this->assertSame('foobar', Base32::decode('mzxw6ytboi'));

        $random = random_bytes(20);
        $this->assertSame($random, Base32::decode(Base32::encode($random)));
    }

    public function test_generate_secret_and_otpauth_uri(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $uri = $totp->otpauthUri($secret, 'timo@muellerhv.de', 'Immoware Hub');

        $this->assertStringStartsWith('otpauth://totp/Immoware%20Hub:timo%40muellerhv.de?', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('issuer=Immoware%20Hub', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
