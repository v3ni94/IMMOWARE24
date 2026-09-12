<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ContactRoleType: string
{
    case Owner = 'owner';
    case Tenant = 'tenant';
    case BoardMember = 'board_member';
    case Prospect = 'prospect';
    case ServiceProvider = 'service_provider';
    case ContactPerson = 'contact_person';
    case Craftsman = 'craftsman';
    case Other = 'other';
}
