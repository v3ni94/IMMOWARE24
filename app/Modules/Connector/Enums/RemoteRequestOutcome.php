<?php

declare(strict_types=1);

namespace App\Modules\Connector\Enums;

enum RemoteRequestOutcome: string
{
    case Success = 'success';
    case ClientError = 'client_error';
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Throttled = 'throttled';
    case ServerError = 'server_error';
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case Blocked = 'blocked';
    case Unknown = 'unknown';

    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401 => self::Unauthorized,
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 429 => self::Throttled,
            $status >= 500 => self::ServerError,
            $status >= 400 => self::ClientError,
            $status >= 100 => self::Success,
            default => self::Unknown,
        };
    }
}
