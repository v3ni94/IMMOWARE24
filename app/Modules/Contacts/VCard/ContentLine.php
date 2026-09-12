<?php

declare(strict_types=1);

namespace App\Modules\Contacts\VCard;

/**
 * Eine entfaltete und dekodierte Content-Line aus vCard oder iCalendar (RFC 6350, RFC 5545).
 * Der Wert ist bereits zeichensatz- und transportdekodiert (Quoted-Printable, Base64, Charset),
 * aber noch nicht von Text-Escapes befreit; dafür stehen value(), components() und list() bereit.
 */
final readonly class ContentLine
{
    /**
     * @param  array<string, array<int, string>>  $parameters  Parametername in Großbuchstaben, Werte als Liste
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public string $rawValue,
        public ?string $group = null,
        public bool $binary = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function parameter(string $name): array
    {
        return $this->parameters[strtoupper($name)] ?? [];
    }

    public function firstParameter(string $name): ?string
    {
        $values = $this->parameter($name);

        return $values === [] ? null : $values[0];
    }

    /**
     * TYPE-Parameter in Kleinbuchstaben (work, home, cell, voice, pref ...).
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        $types = array_map(static fn (string $t): string => strtolower(trim($t)), $this->parameter('TYPE'));

        if ($this->firstParameter('PREF') !== null && ! in_array('pref', $types, true)) {
            $types[] = 'pref';
        }

        return array_values(array_unique(array_filter($types, static fn (string $t): bool => $t !== '')));
    }

    public function hasType(string $type): bool
    {
        return in_array(strtolower($type), $this->types(), true);
    }

    /**
     * Textwert mit aufgelösten Escapes (\\ \n \N \; \,).
     */
    public function value(): string
    {
        return $this->binary ? $this->rawValue : self::unescape($this->rawValue);
    }

    /**
     * Strukturierter Wert (N, ADR, ORG): an unmaskierten Semikola getrennt.
     *
     * @return array<int, string>
     */
    public function components(): array
    {
        return array_map(self::unescape(...), self::splitUnescaped($this->rawValue, ';'));
    }

    /**
     * Listenwert (CATEGORIES, NICKNAME): an unmaskierten Kommata getrennt.
     *
     * @return array<int, string>
     */
    public function list(): array
    {
        $items = array_map(static fn (string $v): string => trim(self::unescape($v)), self::splitUnescaped($this->rawValue, ','));

        return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
    }

    public static function unescape(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char !== '\\' || $i + 1 >= $length) {
                $out .= $char;

                continue;
            }

            $next = $value[++$i];
            $out .= match ($next) {
                'n', 'N' => "\n",
                default => $next,
            };
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public static function splitUnescaped(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char.$value[++$i];

                continue;
            }

            if ($char === $delimiter) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }
}
