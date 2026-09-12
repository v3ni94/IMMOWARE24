<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum DocumentSyncMode: string
{
    case MetadataOnly = 'metadata_only';
    case ContentHash = 'content_hash';
}
