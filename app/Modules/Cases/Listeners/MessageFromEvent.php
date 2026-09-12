<?php

declare(strict_types=1);

namespace App\Modules\Cases\Listeners;

use App\Modules\Gmail\Models\MailMessage;

/**
 * Liest die Nachricht aus einem Ereignis des Moduls Gmail (Eigenschaft message oder messageId), ohne von dessen
 * Klassen abzuhängen.
 */
final class MessageFromEvent
{
    public static function resolve(object $event): ?MailMessage
    {
        $message = property_exists($event, 'message') ? $event->message : null;

        if ($message instanceof MailMessage) {
            return $message;
        }

        $id = property_exists($event, 'messageId') ? $event->messageId : (property_exists($event, 'message_id') ? $event->message_id : null);

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            return MailMessage::query()->allOrganizations()->find((int) $id);
        }

        return null;
    }
}
