<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Mime;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML-Bereinigung für die Anzeige von Nachrichten: Whitelist-Tags und -Attribute, entfernt script, style, iframe,
 * object, embed, form, input, button, Event-Handler und javascript:-URLs. Externe Bilder werden durch einen
 * Platzhalter ersetzt (Tracking-Schutz), Inline-Bilder (cid:) bleiben als Verweis erhalten, Links erhalten
 * rel="noopener noreferrer nofollow" und target="_blank". Eigene Implementierung ohne Paket (DOMDocument).
 */
final class HtmlSanitizer
{
    public const string BLOCKED_IMAGE_PLACEHOLDER = '[Externes Bild blockiert]';

    /** @var array<string, array<int, string>> Tag => erlaubte Attribute */
    private const array ALLOWED = [
        'a' => ['href', 'title'],
        'p' => [], 'br' => [], 'div' => [], 'span' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'small' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'hr' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'font' => [], 'center' => [], 'dl' => [], 'dt' => [], 'dd' => [],
    ];

    /** Tags, die samt Inhalt entfernt werden. */
    private const array DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'svg', 'math', 'link', 'meta', 'base', 'applet', 'frame', 'frameset', 'title', 'head'];

    public function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Ohne Doctype oder html-Rahmen: Encoding über Meta-Hinweis erzwingen, damit Umlaute erhalten bleiben.
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $this->cleanChildren($body, $document);

        $output = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    private function cleanChildren(DOMNode $parent, DOMDocument $document): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node);

                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // Unbekanntes Tag: Inhalt behalten, Hülle entfernen.
                $this->cleanChildren($node, $document);

                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }

                $parent->removeChild($node);

                continue;
            }

            $this->cleanAttributes($node, $tag, $document);

            if ($tag === 'img') {
                $this->handleImage($node, $parent, $document);

                continue;
            }

            $this->cleanChildren($node, $document);
        }
    }

    private function cleanAttributes(DOMElement $node, string $tag, DOMDocument $document): void
    {
        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                $node->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));

            if ($href === '' || ! $this->isSafeLink($href)) {
                $node->removeAttribute('href');
            } else {
                $node->setAttribute('rel', 'noopener noreferrer nofollow');
                $node->setAttribute('target', '_blank');
            }
        }
    }

    private function handleImage(DOMElement $node, DOMNode $parent, DOMDocument $document): void
    {
        $src = trim($node->getAttribute('src'));

        if (str_starts_with(strtolower($src), 'cid:')) {
            // Inline-Bild: Verweis bleibt, die Anzeige löst cid über mail_message_parts.content_id auf.
            return;
        }

        $placeholder = $document->createElement('span');
        $placeholder->setAttribute('class', 'mail-blocked-image');
        $placeholder->setAttribute('data-blocked-src', $this->isSafeLink($src) ? $src : '');
        $alt = trim($node->getAttribute('alt'));
        $placeholder->appendChild($document->createTextNode(self::BLOCKED_IMAGE_PLACEHOLDER.($alt !== '' ? ' '.$alt : '')));
        $parent->replaceChild($placeholder, $node);
    }

    private function isSafeLink(string $url): bool
    {
        $lower = strtolower(trim($url));
        $lower = (string) preg_replace('/[\x00-\x20]/', '', $lower);

        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return false;
        }

        return str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://') || str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:');
    }
}
