<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\CapabilityStatus;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\CapabilityMissingException;
use App\Modules\Connector\Connectors\RestApiSlotConnector;
use PHPUnit\Framework\TestCase;

final class RestApiSlotConnectorTest extends TestCase
{
    public function test_capabilities_are_all_false_and_status_is_waiting(): void
    {
        $connector = new RestApiSlotConnector;

        $this->assertSame('rest_api_slot', $connector->name());
        $this->assertSame([], $connector->capabilities());
        $this->assertSame(['cases.read' => false, 'cases.write' => false], $connector->capabilityFlags());
        $this->assertSame(CapabilityStatus::WaitingForVendorAccess, $connector->status());
    }

    public function test_every_operation_throws_capability_missing_with_vendor_status(): void
    {
        $connector = new RestApiSlotConnector;
        $request = new SyncRequest(1, 'case', SyncMode::cases()[0]);

        foreach (['authenticate', 'testConnection'] as $method) {
            try {
                $connector->{$method}();
                $this->fail($method.' hat keine Exception geworfen.');
            } catch (CapabilityMissingException $e) {
                $this->assertStringContainsString('WAITING_FOR_VENDOR_ACCESS', $e->getMessage());
            }
        }

        foreach (['pull', 'push'] as $method) {
            try {
                $connector->{$method}($request);
                $this->fail($method.' hat keine Exception geworfen.');
            } catch (CapabilityMissingException $e) {
                $this->assertStringContainsString('WAITING_FOR_VENDOR_ACCESS', $e->getMessage());
            }
        }
    }
}
