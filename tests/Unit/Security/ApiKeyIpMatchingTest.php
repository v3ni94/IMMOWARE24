<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Modules\Security\Services\ApiKeyService;
use PHPUnit\Framework\TestCase;

final class ApiKeyIpMatchingTest extends TestCase
{
    public function test_single_ip_and_cidr_matching(): void
    {
        $this->assertTrue(ApiKeyService::ipMatches('10.0.0.5', '10.0.0.5'));
        $this->assertFalse(ApiKeyService::ipMatches('10.0.0.6', '10.0.0.5'));
        $this->assertTrue(ApiKeyService::ipMatches('192.168.1.77', '192.168.1.0/24'));
        $this->assertFalse(ApiKeyService::ipMatches('192.168.2.1', '192.168.1.0/24'));
        $this->assertTrue(ApiKeyService::ipMatches('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(ApiKeyService::ipMatches('172.16.5.9', '172.16.4.0/22'));
        $this->assertFalse(ApiKeyService::ipMatches('172.16.8.1', '172.16.4.0/22'));
        $this->assertTrue(ApiKeyService::ipMatches('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(ApiKeyService::ipMatches('2001:db9::1', '2001:db8::/32'));
        $this->assertFalse(ApiKeyService::ipMatches('10.0.0.1', '2001:db8::/32'));
        $this->assertFalse(ApiKeyService::ipMatches('not-an-ip', '10.0.0.0/8'));
    }
}
