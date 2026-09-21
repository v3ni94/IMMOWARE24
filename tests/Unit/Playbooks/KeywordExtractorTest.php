<?php

declare(strict_types=1);

namespace Tests\Unit\Playbooks;

use App\Modules\Playbooks\Services\KeywordExtractor;
use Tests\TestCase;

final class KeywordExtractorTest extends TestCase
{
    public function test_extract_drops_short_words_stopwords_and_duplicates(): void
    {
        $extractor = new KeywordExtractor(config());

        $keywords = $extractor->extract('Sehr geehrte Damen und Herren, unser Heizung Heizung ist seit gestern kaputt.');

        $this->assertContains('heizung', $keywords);
        $this->assertContains('kaputt', $keywords);
        $this->assertContains('gestern', $keywords);
        $this->assertNotContains('und', $keywords);
        $this->assertNotContains('sehr', $keywords);
        $this->assertSame(array_values(array_unique($keywords)), $keywords);
    }

    public function test_extract_respects_max_keywords_limit(): void
    {
        config()->set('hub.playbooks.keywords.max_keywords', 2);
        $extractor = new KeywordExtractor(config());

        $keywords = $extractor->extract('Heizung Wasserschaden Rohrbruch Stromausfall');

        $this->assertCount(2, $keywords);
    }

    public function test_similarity_percent_is_jaccard_and_zero_for_empty_lists(): void
    {
        $extractor = new KeywordExtractor(config());

        $this->assertSame(0, $extractor->similarityPercent([], ['heizung']));
        $this->assertSame(100, $extractor->similarityPercent(['heizung', 'kaputt'], ['heizung', 'kaputt']));
        $this->assertSame(33, $extractor->similarityPercent(['heizung', 'kaputt'], ['heizung', 'wasser']));
    }
}
