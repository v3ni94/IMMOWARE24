<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum AuditSource: string
{
    case ImmowareSync = 'immoware_sync';
    case Api = 'api';
    case User = 'user';
    case System = 'system';
    case N8n = 'n8n';
    case Mcp = 'mcp';
    case Import = 'import';
    case Webhook = 'webhook';
}
