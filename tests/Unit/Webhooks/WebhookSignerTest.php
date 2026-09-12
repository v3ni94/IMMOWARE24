<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

use App\Modules\Webhooks\Services\WebhookSigner;
use PHPUnit\Framework\TestCase;

final class WebhookSignerTest extends TestCase
{
    public function test_signature_format_and_rotation(): void
    {
        $signer = new WebhookSigner;
        $body = '{"event":"x"}';
        $ts = 1_757_664_000;

        $single = $signer->signature('neu', $ts, $body);
        $this->assertSame('t='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, 'neu'), $single);

        $rotated = $signer->signature('neu', $ts, $body, 'alt');
        $this->assertSame(2, substr_count($rotated, 'v1='));
        $this->assertTrue($signer->verify($rotated, $body, 'alt', $ts + 10, 300));
        $this->assertTrue($signer->verify($rotated, $body, 'neu', $ts + 10, 300));
        $this->assertFalse($signer->verify($rotated, $body, 'neu', $ts + 301, 300));
        $this->assertFalse($signer->verify('kaputt', $body, 'neu', $ts, 300));
    }
}
