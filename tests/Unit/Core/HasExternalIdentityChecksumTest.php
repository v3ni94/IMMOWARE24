<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Contacts\Models\Contact;
use PHPUnit\Framework\TestCase;

final class HasExternalIdentityChecksumTest extends TestCase
{
    public function test_checksum_is_deterministic(): void
    {
        $data = ['last_name' => 'Müller', 'first_name' => 'Timo', 'emails' => [['type' => 'work', 'value' => 'a@b.de']]];

        $this->assertSame(Contact::computeChecksum($data), Contact::computeChecksum($data));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Contact::computeChecksum($data));
    }

    public function test_checksum_is_independent_of_key_order_including_nested_arrays(): void
    {
        $a = ['first_name' => 'Timo', 'last_name' => 'Müller', 'address' => ['city' => 'Hilden', 'zip' => '40721']];
        $b = ['address' => ['zip' => '40721', 'city' => 'Hilden'], 'last_name' => 'Müller', 'first_name' => 'Timo'];

        $this->assertSame(Contact::computeChecksum($a), Contact::computeChecksum($b));
    }

    public function test_checksum_changes_when_a_value_changes_and_list_order_matters(): void
    {
        $base = ['name' => 'A', 'tags' => ['x', 'y']];

        $this->assertNotSame(Contact::computeChecksum($base), Contact::computeChecksum(['name' => 'B', 'tags' => ['x', 'y']]));
        $this->assertNotSame(Contact::computeChecksum($base), Contact::computeChecksum(['name' => 'A', 'tags' => ['y', 'x']]));
    }
}
