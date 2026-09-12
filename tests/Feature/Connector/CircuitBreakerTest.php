<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Exceptions\CircuitOpenException;
use App\Modules\Connector\Enums\CircuitState;
use App\Modules\Connector\Services\CircuitBreaker;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class CircuitBreakerTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_opens_after_threshold_failures_within_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $breaker = $this->app->make(CircuitBreaker::class);
        $key = CircuitBreaker::keyFor(1);

        for ($i = 0; $i < 4; $i++) {
            $breaker->recordStatus($key, 500);
        }

        $this->assertSame(CircuitState::Closed, $breaker->state($key));
        $breaker->assertAvailable($key);

        $breaker->recordStatus($key, 502);

        $this->assertSame(CircuitState::Open, $breaker->state($key));
        $this->expectException(CircuitOpenException::class);
        $breaker->assertAvailable($key);
    }

    public function test_old_failures_outside_window_do_not_count(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $breaker = $this->app->make(CircuitBreaker::class);
        $key = CircuitBreaker::keyFor(2);

        for ($i = 0; $i < 4; $i++) {
            $breaker->recordStatus($key, 500);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:03:00'));
        $breaker->recordStatus($key, 500);

        $this->assertSame(CircuitState::Closed, $breaker->state($key));
    }

    public function test_401_opens_immediately_and_403_never_opens(): void
    {
        $breaker = $this->app->make(CircuitBreaker::class);

        $forbidden = CircuitBreaker::keyFor(3);
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordStatus($forbidden, 403);
        }
        $this->assertSame(CircuitState::Closed, $breaker->state($forbidden));

        $unauthorized = CircuitBreaker::keyFor(4);
        $breaker->recordStatus($unauthorized, 401);
        $this->assertSame(CircuitState::Open, $breaker->state($unauthorized));
    }

    public function test_429_with_retry_after_does_not_count(): void
    {
        $breaker = $this->app->make(CircuitBreaker::class);
        $key = CircuitBreaker::keyFor(5);

        for ($i = 0; $i < 6; $i++) {
            $breaker->recordStatus($key, 429, false, true);
        }

        $this->assertSame(CircuitState::Closed, $breaker->state($key));
    }

    public function test_half_open_allows_single_trial_and_closes_on_success(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $breaker = $this->app->make(CircuitBreaker::class);
        $key = CircuitBreaker::keyFor(6);
        $breaker->recordFailure($key, true);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:10:01'));
        $this->assertSame(CircuitState::HalfOpen, $breaker->state($key));

        $breaker->assertAvailable($key);

        try {
            $breaker->assertAvailable($key);
            $this->fail('Zweiter Testrequest im half_open muss abgelehnt werden.');
        } catch (CircuitOpenException) {
            $this->addToAssertionCount(1);
        }

        $breaker->recordStatus($key, 207);
        $this->assertSame(CircuitState::Closed, $breaker->state($key));
        $this->assertSame(0, $breaker->snapshot($key)['open_cycles']);
    }

    public function test_failure_in_half_open_reopens_and_third_cycle_extends(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $breaker = $this->app->make(CircuitBreaker::class);
        $key = CircuitBreaker::keyFor(7);

        $breaker->recordFailure($key, true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:10:01'));
        $breaker->assertAvailable($key);
        $breaker->recordStatus($key, 500);
        $this->assertSame(CircuitState::Open, $breaker->state($key));
        $this->assertSame(2, $breaker->snapshot($key)['open_cycles']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 10:20:02'));
        $breaker->assertAvailable($key);
        $breaker->recordStatus($key, 500);
        $snapshot = $breaker->snapshot($key);
        $this->assertSame(3, $snapshot['open_cycles']);
        $this->assertSame(CarbonImmutable::now()->getTimestamp() + 3600, $snapshot['open_until']);
    }
}
