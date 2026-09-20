<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Modules\Ai\Services\AnthropicProvider;
use App\Modules\Ai\Services\OpenAiProvider;
use App\Modules\Ai\Services\PromptBuilder;
use App\Modules\Ai\Services\SelectingAiProvider;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auswahl unter mehreren KI-Anbietern anhand hub.ai.provider_priority. Nur Http::fake(), kein Live-Test.
 */
final class SelectingAiProviderTest extends TestCase
{
    private function selecting(): SelectingAiProvider
    {
        return new SelectingAiProvider(
            config(),
            new OpenAiProvider(config(), new PromptBuilder),
            new AnthropicProvider(config(), new PromptBuilder),
        );
    }

    private function configureBoth(): void
    {
        config()->set('hub.ai.api_key', 'sk-test-key-1234567890');
        config()->set('hub.ai.model', 'openai-modell');
        config()->set('hub.ai.retry.times', 0);
        config()->set('hub.ai.anthropic.api_key', 'sk-ant-test-key-1234567890');
        config()->set('hub.ai.anthropic.model', 'anthropic-modell');
        config()->set('hub.ai.anthropic.retry.times', 0);
    }

    public function test_openai_is_used_first_when_both_are_configured(): void
    {
        $this->configureBoth();
        config()->set('hub.ai.provider_priority', ['openai', 'anthropic']);

        Http::fake([
            'https://api.openai.com/v1/*' => Http::response([
                'id' => 'r1', 'status' => 'completed', 'model' => 'openai-modell',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"summary":"von openai"}']]]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
            ]),
            'https://api.anthropic.com/v1/*' => Http::response(['stop_reason' => 'end_turn', 'content' => []]),
        ]);

        $result = $this->selecting()->structured('summarize', [], ['type' => 'object']);

        $this->assertSame('openai', $result['meta']['provider']);
        Http::assertSentCount(1);
    }

    public function test_falls_back_to_anthropic_when_openai_is_not_configured(): void
    {
        config()->set('hub.ai.api_key', '');
        config()->set('hub.ai.model', '');
        config()->set('hub.ai.anthropic.api_key', 'sk-ant-test-key-1234567890');
        config()->set('hub.ai.anthropic.model', 'anthropic-modell');
        config()->set('hub.ai.anthropic.retry.times', 0);
        config()->set('hub.ai.provider_priority', ['openai', 'anthropic']);

        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'r1', 'model' => 'anthropic-modell', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'name' => 'structured_output', 'input' => ['summary' => 'von anthropic']]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])]);

        $result = $this->selecting()->structured('summarize', [], ['type' => 'object']);

        $this->assertSame('anthropic', $result['meta']['provider']);
        Http::assertSentCount(1);
    }

    public function test_falls_back_to_anthropic_when_openai_is_configured_but_unreachable(): void
    {
        $this->configureBoth();
        config()->set('hub.ai.provider_priority', ['openai', 'anthropic']);

        Http::fake([
            'https://api.openai.com/v1/*' => Http::response('down', 500),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'r1', 'model' => 'anthropic-modell', 'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'name' => 'structured_output', 'input' => ['summary' => 'ausweichend']]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
            ]),
        ]);

        $result = $this->selecting()->structured('summarize', [], ['type' => 'object']);

        $this->assertSame('anthropic', $result['meta']['provider']);
        $this->assertSame('ausweichend', $result['summary']);
    }

    public function test_throws_remote_exception_when_all_configured_providers_fail(): void
    {
        $this->configureBoth();
        config()->set('hub.ai.provider_priority', ['openai', 'anthropic']);

        Http::fake([
            'https://api.openai.com/v1/*' => Http::response('down', 500),
            'https://api.anthropic.com/v1/*' => Http::response('down', 500),
        ]);

        $this->expectException(MailRemoteException::class);
        $this->selecting()->structured('summarize', [], ['type' => 'object']);
    }

    public function test_throws_not_configured_when_no_provider_is_configured(): void
    {
        config()->set('hub.ai.api_key', '');
        config()->set('hub.ai.model', '');
        config()->set('hub.ai.anthropic.api_key', '');
        config()->set('hub.ai.anthropic.model', '');

        Http::fake();

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->selecting()->structured('summarize', [], ['type' => 'object']);
        Http::assertNothingSent();
    }

    public function test_unknown_priority_entries_fall_back_to_default_order(): void
    {
        $this->configureBoth();
        config()->set('hub.ai.provider_priority', ['does-not-exist']);

        Http::fake(['https://api.openai.com/v1/*' => Http::response([
            'id' => 'r1', 'status' => 'completed', 'model' => 'openai-modell',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"summary":"trotzdem openai"}']]]],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ])]);

        $result = $this->selecting()->structured('summarize', [], ['type' => 'object']);

        $this->assertSame('openai', $result['meta']['provider']);
    }
}
