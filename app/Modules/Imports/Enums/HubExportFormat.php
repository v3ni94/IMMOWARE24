<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

enum HubExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
}
