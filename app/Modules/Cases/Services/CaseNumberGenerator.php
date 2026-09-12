<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Cases\Models\MailCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Vorgangsnummer V-JJJJ-NNNNNN, fortlaufend je Jahr, mandantenübergreifend eindeutig (Unique mail_cases.case_number).
 * Serialisiert über Cache-Lock; bei Kollision durch Race wirft der Unique-Index, der Aufrufer wiederholt.
 */
final class CaseNumberGenerator
{
    public function __construct(private readonly Repository $config) {}

    public function next(?CarbonImmutable $at = null): string
    {
        $year = ($at ?? CarbonImmutable::now())->format('Y');
        $prefix = (string) $this->config->get('hub.cases.number_prefix', 'V').'-'.$year.'-';

        return Cache::lock('mail:case-number:'.$year, 5)->block(5, function () use ($prefix): string {
            $last = MailCase::query()
                ->allOrganizations()
                ->where('case_number', 'like', $prefix.'%')
                ->orderByDesc('case_number')
                ->value('case_number');

            $sequence = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
