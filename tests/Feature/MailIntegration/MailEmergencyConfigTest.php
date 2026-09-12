<?php

declare(strict_types=1);

namespace Tests\Feature\MailIntegration;

use Tests\TestCase;

/**
 * Schlüssel hub.mail.emergency.test_recipient (für hub:doctor) und hub.mail.retention sind vorhanden und sicher vorbelegt.
 */
final class MailEmergencyConfigTest extends TestCase
{
    public function test_emergency_test_recipient_key_exists_and_defaults_to_null(): void
    {
        $this->assertArrayHasKey('test_recipient', (array) config('hub.mail.emergency'));
        $this->assertNull(config('hub.mail.emergency.test_recipient'));
    }

    public function test_retention_defaults_are_disabled_and_conservative(): void
    {
        $retention = (array) config('hub.mail.retention');

        $this->assertFalse((bool) $retention['enabled']);
        $this->assertGreaterThanOrEqual(3650, (int) $retention['messages_days']);
        $this->assertGreaterThanOrEqual(3650, (int) $retention['closed_cases_days']);
        $this->assertSame(30, (int) $retention['push_events_days']);
    }
}
