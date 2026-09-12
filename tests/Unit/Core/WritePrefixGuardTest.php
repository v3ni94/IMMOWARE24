<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\WritePrefixGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WritePrefixGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function prefixes(): iterable
    {
        yield 'Posteingang' => ['/Posteingang/', true];
        yield 'Unterordner' => ['/Posteingang/Hub/', true];
        yield 'null' => [null, false];
        yield 'leer' => ['', false];
        yield 'Wurzel' => ['/', false];
        yield 'ohne führenden Schrägstrich' => ['Posteingang/', false];
        yield 'ohne abschließenden Schrägstrich' => ['/Posteingang', false];
        yield 'Dokumente' => ['/Dokumente/', false];
        yield 'Dokumente Unterordner' => ['/dokumente/2026/', false];
        yield 'Punktsegment' => ['/Posteingang/../Dokumente/', false];
        yield 'doppelter Schrägstrich' => ['//Posteingang/', false];
        yield 'Backslash' => ['/Posteingang\\Hub/', false];
        yield 'Steuerzeichen' => ["/Posteingang\n/", false];
    }

    #[DataProvider('prefixes')]
    public function test_prefix_rule(?string $prefix, bool $allowed): void
    {
        $this->assertSame($allowed, WritePrefixGuard::allows($prefix));
        $this->assertSame($allowed, WritePrefixGuard::reason($prefix) === null);
    }
}
