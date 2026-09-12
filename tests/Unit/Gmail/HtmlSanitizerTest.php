<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Modules\Gmail\Mime\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function test_removes_scripts_styles_forms_and_event_handlers(): void
    {
        $html = '<div onclick="alert(1)"><script>alert("x")</script><style>body{color:red}</style>'
            .'<form action="https://evil.example"><input name="pw"><button>Los</button></form>'
            .'<p style="color:red" class="x">Guten Tag <b>Müller</b></p><iframe src="https://evil.example"></iframe></div>';

        $clean = (new HtmlSanitizer)->sanitize($html);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<style', $clean);
        $this->assertStringNotContainsString('<form', $clean);
        $this->assertStringNotContainsString('<input', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringContainsString('<p>Guten Tag <b>Müller</b></p>', $clean);
    }

    public function test_replaces_external_images_and_keeps_inline_cid_images(): void
    {
        $html = '<p><img src="https://tracker.example/pixel.gif" alt="Pixel"><img src="cid:logo@example" alt="Logo"></p>';

        $clean = (new HtmlSanitizer)->sanitize($html);

        $this->assertStringNotContainsString('<img src="https://tracker.example', $clean);
        $this->assertStringContainsString('<span class="mail-blocked-image" data-blocked-src="https://tracker.example/pixel.gif">', $clean);
        $this->assertStringContainsString(HtmlSanitizer::BLOCKED_IMAGE_PLACEHOLDER.' Pixel', $clean);
        $this->assertStringContainsString('<img src="cid:logo@example" alt="Logo">', $clean);
    }

    public function test_links_get_noopener_and_javascript_urls_are_dropped(): void
    {
        $html = '<a href="https://muellerhv.de/x" target="_self">Link</a> <a href="javascript:alert(1)">Böse</a>';

        $clean = (new HtmlSanitizer)->sanitize($html);

        $this->assertStringContainsString('<a href="https://muellerhv.de/x" rel="noopener noreferrer nofollow" target="_blank">Link</a>', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<a>Böse</a>', $clean);
    }
}
