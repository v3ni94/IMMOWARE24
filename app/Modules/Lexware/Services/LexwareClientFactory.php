<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Services;

use App\Core\Support\SecretMasker;
use App\Modules\Lexware\DTO\LexwareCredentials;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Baut LexwareClient je Zugang (Organisation, Gesellschaft). Ohne Zugang: LexwareNotConfiguredException.
 */
final class LexwareClientFactory
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly LexwareRateLimiter $limiter,
        private readonly SecretMasker $masker,
        private readonly LexwareConnectionResolver $resolver,
        private readonly Repository $config,
    ) {}

    public function for(LexwareCredentials $credentials): LexwareClient
    {
        return new LexwareClient($this->http, $this->limiter, $this->masker, $credentials, max(3, (int) $this->config->get('hub.lexware.timeout_seconds', 15)));
    }

    public function forOrganization(?int $organizationId, ?string $legalEntityCode = null): LexwareClient
    {
        return $this->for($this->resolver->require($organizationId, $legalEntityCode));
    }
}
