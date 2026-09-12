<?php

declare(strict_types=1);

namespace Tests\Unit\Sla;

use App\Modules\Cases\Enums\Priority;
use App\Modules\Sla\Services\PriorityClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class PriorityClassifierTest extends TestCase
{
    private PriorityClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $config = require dirname(__DIR__, 3).'/config/hub/sla.php';
        $this->classifier = new PriorityClassifier(new Repository(['hub' => ['sla' => $config]]));
    }

    public function test_emergency_keywords_yield_p0(): void
    {
        foreach (['Wasser tritt aus der Decke', 'Es brennt, Brand im Keller', 'Gasgeruch im Treppenhaus', 'Akute Gefahr durch lose Fassadenteile'] as $text) {
            $decision = $this->classifier->classify('Dringend', $text);
            $this->assertSame(Priority::P0, $decision->priority, $text);
            $this->assertTrue($decision->emergency);
            $this->assertNotNull($decision->matchedRule);
        }
    }

    public function test_negated_or_historic_wording_is_conservative_p1_with_review_hint(): void
    {
        $decision = $this->classifier->classify('Rückfrage', 'Nach dem Rohrbruch letztes Jahr ist alles behoben, ich brauche nur die Rechnung.');

        $this->assertSame(Priority::P1, $decision->priority);
        $this->assertFalse($decision->emergency);
        $this->assertNotNull($decision->reviewHint);

        $negated = $this->classifier->classify('Info', 'Es gibt keinen Gasgeruch mehr, der Techniker war da.');
        $this->assertSame(Priority::P1, $negated->priority);
        $this->assertFalse($negated->emergency);
    }

    public function test_urgent_keywords_yield_p1_and_default_is_p2(): void
    {
        $this->assertSame(Priority::P1, $this->classifier->classify('Heizungsausfall', 'Seit heute Morgen kalt.')->priority);
        $this->assertSame(Priority::P2, $this->classifier->classify('Adressänderung', 'Meine neue Adresse lautet ...')->priority);
    }

    public function test_old_mail_gets_p3_but_emergency_terms_give_p1_with_hint(): void
    {
        $received = CarbonImmutable::parse('2026-09-01 10:00', 'UTC');
        $imported = CarbonImmutable::parse('2026-09-12 10:00', 'UTC');

        $stale = $this->classifier->classify('Frage', 'Wie hoch ist das Hausgeld?', $received, $imported);
        $this->assertSame(Priority::P3, $stale->priority);

        $staleEmergency = $this->classifier->classify('Wasser tritt aus', 'Im Keller tritt Wasser aus.', $received, $imported);
        $this->assertSame(Priority::P1, $staleEmergency->priority);
        $this->assertFalse($staleEmergency->emergency, 'Altmail: Hinweis an Teamleitung statt Notfall-Eskalation.');
        $this->assertNotNull($staleEmergency->reviewHint);
    }

    public function test_ai_can_never_downgrade_p0(): void
    {
        $rule = $this->classifier->classify('Brand', 'Es brennt im Keller.');

        $this->assertSame(Priority::P0, $this->classifier->merge($rule, Priority::P3, true)->priority);
        $this->assertSame(Priority::P0, $this->classifier->merge($rule, Priority::P2, false)->priority);

        $normal = $this->classifier->classify('Frage', 'Allgemeine Frage.');
        $this->assertSame(Priority::P2, $this->classifier->merge($normal, Priority::P1, false)->priority, 'Unbestätigter KI-Vorschlag ändert nichts.');
        $this->assertSame(Priority::P1, $this->classifier->merge($normal, Priority::P1, true)->priority);
        $this->assertSame(Priority::P2, $this->classifier->merge($normal, Priority::P3, true)->priority, 'Bestätigter Vorschlag stuft nicht herab.');
    }
}
