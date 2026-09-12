<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Modules\Gmail\Services\Push\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Http;
use Tests\Support\Gmail\SignedIdToken;
use Tests\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    private SignedIdToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new SignedIdToken;
        config()->set('hub.gmail.push.audience', 'https://mail.muellerhv.de/mail/gmail/push');
        config()->set('hub.gmail.push.service_account_email', 'pubsub-push@projekt.iam.gserviceaccount.com');
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->tokens->jwks())]);
    }

    public function test_accepts_valid_token(): void
    {
        $result = $this->verifier()->verify($this->tokens->issue());

        $this->assertSame(GoogleIdTokenVerifier::RESULT_OK, $result['result']);
        $this->assertSame('pubsub-push@projekt.iam.gserviceaccount.com', $result['claims']['email']);
    }

    public function test_rejects_wrong_audience(): void
    {
        $result = $this->verifier()->verify($this->tokens->issue(['aud' => 'https://anderer-host.example/push']));

        $this->assertSame(GoogleIdTokenVerifier::RESULT_BAD_AUDIENCE, $result['result']);
    }

    public function test_rejects_wrong_email_expired_token_tampered_signature_and_unknown_key(): void
    {
        $verifier = $this->verifier();

        $this->assertSame(GoogleIdTokenVerifier::RESULT_BAD_EMAIL, $verifier->verify($this->tokens->issue(['email' => 'fremd@example.com']))['result']);
        $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify($this->tokens->issue(['exp' => time() - 600]))['result']);
        $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify($this->tokens->issue(['iss' => 'https://evil.example']))['result']);

        $valid = $this->tokens->issue();
        $segments = explode('.', $valid);
        $segments[1] = rtrim(strtr(base64_encode(json_encode(['aud' => 'https://mail.muellerhv.de/mail/gmail/push', 'email' => 'pubsub-push@projekt.iam.gserviceaccount.com', 'email_verified' => true, 'iss' => 'https://accounts.google.com', 'exp' => time() + 300, 'iat' => time()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify(implode('.', $segments))['result']);

        $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify($this->tokens->issue([], 'unbekannte-kid'))['result']);
        $this->assertSame(GoogleIdTokenVerifier::RESULT_MISSING, $verifier->verify(null)['result']);
    }

    public function test_unknown_kid_triggers_at_most_one_certificate_reload_per_interval(): void
    {
        $verifier = $this->verifier();
        $this->assertSame(GoogleIdTokenVerifier::RESULT_OK, $verifier->verify($this->tokens->issue())['result']);
        Http::assertSentCount(1);

        foreach (range(1, 25) as $n) {
            $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify($this->tokens->issue([], 'zufall-'.$n))['result']);
        }

        Http::assertSentCount(2, 'Nur ein erzwungenes Neuladen je Mindestabstand, kein Aufruf je unbekannter kid.');

        $this->assertSame(GoogleIdTokenVerifier::RESULT_INVALID, $verifier->verify($this->tokens->issue([], 'zufall-1'))['result']);
        Http::assertSentCount(2, 'Unbekannte kid ist negativ gecacht.');
        $this->assertSame(GoogleIdTokenVerifier::RESULT_OK, $verifier->verify($this->tokens->issue())['result'], 'Bekannte Schlüssel bleiben nutzbar.');
    }

    private function verifier(): GoogleIdTokenVerifier
    {
        return $this->app->make(GoogleIdTokenVerifier::class);
    }
}
