<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    private SecretMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new SecretMasker;
    }

    public function test_masks_sensitive_keys_recursively(): void
    {
        $result = $this->masker->maskArray([
            'username' => 'hub-read',
            'password' => 'geheim',
            'nested' => ['api_key' => 'abc', 'Authorization' => 'Basic xyz', 'label' => 'ok'],
            'headers' => ['X-Hub-Signature' => 'v1=deadbeef'],
        ]);

        $this->assertSame('hub-read', $result['username']);
        $this->assertSame(SecretMasker::MASK, $result['password']);
        $this->assertSame(SecretMasker::MASK, $result['nested']['api_key']);
        $this->assertSame(SecretMasker::MASK, $result['nested']['Authorization']);
        $this->assertSame('ok', $result['nested']['label']);
        $this->assertSame(SecretMasker::MASK, $result['headers']['X-Hub-Signature']);
    }

    public function test_masks_tokens_and_credentials_inside_strings(): void
    {
        $this->assertSame('Authorization: Basic ***', $this->masker->maskString('Authorization: Basic aHViOnNlY3JldA=='));
        $this->assertSame('Bearer ***', $this->masker->maskString('Bearer eyJhbGciOiJIUzI1NiJ9.abc'));
        $this->assertSame('url=https://hub-read:***@dav.example.test/dav', $this->masker->maskString('url=https://hub-read:Pa55wort!@dav.example.test/dav'));
        $this->assertSame('login failed for password=***', $this->masker->maskString('login failed for password=geheim123'));
        $this->assertSame('{"token":"***"}', $this->masker->maskString('{"token":"abcdef"}'));
    }

    public function test_leaves_harmless_values_untouched(): void
    {
        $this->assertSame('PROPFIND /Posteingang/ 207', $this->masker->maskString('PROPFIND /Posteingang/ 207'));
        $this->assertFalse($this->masker->isSensitiveKey('filename'));
    }
}
