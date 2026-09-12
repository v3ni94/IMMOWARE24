<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

use App\Core\Support\SecretMasker;

/**
 * Entschlüsselte Zugangsdaten, ausschließlich im Speicher. Kein Serialisieren, kein var_dump,
 * kein JSON. Das Passwort ist über __debugInfo und __serialize maskiert.
 */
final class ConnectorCredentials
{
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {}

    public function password(): string
    {
        return $this->password;
    }

    public function isEmpty(): bool
    {
        return $this->username === '' && $this->password === '';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => SecretMasker::MASK];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('ConnectorCredentials dürfen nicht serialisiert werden.');
    }

    /**
     * @param  array<string, string>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('ConnectorCredentials dürfen nicht deserialisiert werden.');
    }
}
