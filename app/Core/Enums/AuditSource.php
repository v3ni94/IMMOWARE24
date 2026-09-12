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
    // Mail-Modul (docs/mail/02-datenmodell.md, Abschnitt 7), additiv 13.09.2026.
    case Mail = 'mail';
    case GmailPush = 'gmail_push';
    case Ai = 'ai';
}
