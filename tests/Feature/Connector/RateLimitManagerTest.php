<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Contracts\RateLimiterInterface;
use App\Core\Exceptions\RateLimitedException;
use App\Modules\Connector\Services\RateLimitManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class RateLimitManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_interface_bound_and_bucket_respects_rps_and_concurrency(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $limiter = $this->app->make(RateLimiterInterface::class);
        $this->assertInstanceOf(RateLimitManager::class, $limiter);

        $key = RateLimitManager::keyFor(1, 'read');
        $limiter->configure($key, 2.0, 2);

        $this->assertSame(2, $limiter->remaining($key));
        $this->assertTrue($limiter->tryAcquire($key));
        $this->assertTrue($limiter->tryAcquire($key));
        $this->assertFalse($limiter->tryAcquire($key), 'Bucket leer und Concurrency ausgeschöpft');

        // Slots zurückgeben, aber keine Zeit vergangen: Bucket noch leer
        $limiter->release($key);
        $limiter->release($key);
        $this->assertFalse($limiter->tryAcquire($key));

        // Nach 500 ms ist bei 2 rps genau ein Token nachgefüllt
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00.500'));
        $this->assertTrue($limiter->tryAcquire($key));
        $this->assertFalse($limiter->tryAcquire($key));
    }

    public function test_concurrency_limit_blocks_even_with_tokens(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor(2, 'read');
        $limiter->configure($key, 10.0, 1);

        $this->assertTrue($limiter->tryAcquire($key));
        $this->assertFalse($limiter->tryAcquire($key));
        $limiter->release($key);
        $this->assertTrue($limiter->tryAcquire($key));
    }

    public function test_429_halves_rate_for_ten_minutes_and_counts_metric(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor(3, 'read');
        $limiter->configure($key, 2.0, 2);

        $limiter->reportResponse($key, 429, 100);

        $this->assertTrue($limiter->isThrottled($key));
        $this->assertSame(1.0, $limiter->effectiveRps($key));
        $this->assertSame(1, $limiter->metrics($key)['429_count']);

        $limiter->reportResponse($key, 503, 100);
        $this->assertSame(2, $limiter->metrics($key)['429_count']);

        $limiter->reportResponse($key, 502, 100);
        $this->assertSame(1, $limiter->metrics($key)['5xx_count']);

        $limiter->reportResponse($key, null, null, true);
        $this->assertSame(1, $limiter->metrics($key)['timeout_count']);

        // Untergrenze 0,25 rps
        $limiter->configure($key, 0.3, 2);
        $this->assertSame(0.25, $limiter->effectiveRps($key));
    }

    public function test_latency_spike_throttles(): void
    {
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor(4, 'read');

        foreach ([100, 120, 90, 110] as $ms) {
            $limiter->reportResponse($key, 207, $ms);
        }

        $this->assertFalse($limiter->isThrottled($key));

        $limiter->reportResponse($key, 207, 9000);

        $this->assertTrue($limiter->isThrottled($key));
        $this->assertSame(1, $limiter->metrics($key)['latency_spike_count']);
    }

    public function test_acquire_throws_after_max_wait(): void
    {
        Sleep::fake();
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor(5, 'read');
        $limiter->configure($key, 2.0, 1);
        $this->assertTrue($limiter->tryAcquire($key));

        $this->expectException(RateLimitedException::class);
        $limiter->acquire($key, 60);
    }

    public function test_reset_clears_state(): void
    {
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor(6, 'write');
        $limiter->reportResponse($key, 429, 10);
        $limiter->reset($key);

        $this->assertFalse($limiter->isThrottled($key));
        $this->assertSame(0, $limiter->metrics($key)['429_count']);
    }
}
