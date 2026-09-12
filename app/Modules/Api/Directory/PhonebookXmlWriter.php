<?php

declare(strict_types=1);

namespace App\Modules\Api\Directory;

use XMLWriter;

/**
 * Generische XML-Telefonbuchstruktur. Kein Herstellerformat; Konsumenten transformieren bei Bedarf selbst.
 */
final class PhonebookXmlWriter
{
    /**
     * @param  iterable<DirectoryEntry>  $entries
     */
    public function write(iterable $entries, string $generatedAt): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('phonebook');
        $xml->writeAttribute('generator', 'immoware-hub');
        $xml->writeAttribute('generated_at', $generatedAt);

        foreach ($entries as $entry) {
            $xml->startElement('entry');
            $xml->writeAttribute('id', (string) $entry->id);
            $xml->writeElement('name', $entry->displayName);

            if ($entry->firstName !== null) {
                $xml->writeElement('first_name', $entry->firstName);
            }

            if ($entry->lastName !== null) {
                $xml->writeElement('last_name', $entry->lastName);
            }

            if ($entry->company !== null) {
                $xml->writeElement('company', $entry->company);
            }

            $xml->startElement('phones');

            foreach ($entry->phones as $phone) {
                $xml->startElement('phone');
                $xml->writeAttribute('type', 'work');
                $xml->text($phone);
                $xml->endElement();
            }

            foreach ($entry->mobiles as $mobile) {
                $xml->startElement('phone');
                $xml->writeAttribute('type', 'mobile');
                $xml->text($mobile);
                $xml->endElement();
            }

            $xml->endElement();

            $xml->startElement('emails');

            foreach ($entry->emails as $email) {
                $xml->writeElement('email', $email);
            }

            $xml->endElement();

            $xml->startElement('roles');

            foreach ($entry->roles as $role) {
                $xml->startElement('role');
                $xml->writeAttribute('type', $role['role']);

                if ($role['property'] !== null) {
                    $xml->writeAttribute('property', $role['property']);
                }

                if ($role['unit'] !== null) {
                    $xml->writeAttribute('unit', $role['unit']);
                }

                $xml->endElement();
            }

            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
