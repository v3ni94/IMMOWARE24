<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Modules\Actions\Support\DiffHasher;
use PHPUnit\Framework\TestCase;

final class DiffHasherTest extends TestCase
{
    public function test_hash_is_independent_of_key_order_and_whitespace(): void
    {
        $hasher = new DiffHasher;

        $this->assertSame($hasher->hash(['b' => 1, 'a' => ' x ']), $hasher->hash(['a' => 'x', 'b' => 1]));
        $this->assertNotSame($hasher->hash(['a' => 'x']), $hasher->hash(['a' => 'y']));
    }

    public function test_diff_lists_only_changed_fields(): void
    {
        $diff = (new DiffHasher)->diff(['street' => 'Alt 1', 'city' => 'Hilden'], ['street' => 'Neu 2', 'city' => 'Hilden']);

        $this->assertSame(['street' => ['old' => 'Alt 1', 'new' => 'Neu 2']], $diff);
    }
}
