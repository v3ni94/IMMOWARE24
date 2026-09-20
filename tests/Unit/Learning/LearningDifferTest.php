<?php

declare(strict_types=1);

namespace Tests\Unit\Learning;

use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Services\LearningDiffer;
use Tests\TestCase;

final class LearningDifferTest extends TestCase
{
    public function test_first_run_without_previous_facts_counts_as_changed(): void
    {
        $diff = (new LearningDiffer)->diff(LearningKind::WebDav, null, ['folders' => []]);

        $this->assertTrue($diff['changed']);
        $this->assertTrue($diff['first_run']);
    }

    public function test_webdav_diff_detects_new_and_removed_folders_and_scope(): void
    {
        $previous = ['folders' => [
            ['path' => '/Posteingang/', 'in_configured_scope' => true],
            ['path' => '/Alt/', 'in_configured_scope' => false],
        ]];
        $current = ['folders' => [
            ['path' => '/Posteingang/', 'in_configured_scope' => true],
            ['path' => '/Neu/', 'in_configured_scope' => false],
        ]];

        $diff = (new LearningDiffer)->diff(LearningKind::WebDav, $previous, $current);

        $this->assertTrue($diff['changed']);
        $this->assertSame(['/Neu/'], $diff['new_folders']);
        $this->assertSame(['/Alt/'], $diff['removed_folders']);
        $this->assertSame(['/Neu/'], $diff['new_folders_outside_configured_scope']);
    }

    public function test_webdav_diff_reports_no_change_for_identical_facts(): void
    {
        $facts = ['folders' => [['path' => '/Posteingang/', 'in_configured_scope' => true]]];

        $diff = (new LearningDiffer)->diff(LearningKind::WebDav, $facts, $facts);

        $this->assertFalse($diff['changed']);
        $this->assertSame([], $diff['new_folders']);
        $this->assertSame([], $diff['removed_folders']);
    }

    public function test_field_usage_diff_detects_newly_and_no_longer_used_fields_and_reachability(): void
    {
        $previous = ['collection' => ['reachable' => true], 'field_usage' => ['birth_date' => 0, 'company_id' => 5]];
        $current = ['collection' => ['reachable' => false], 'field_usage' => ['birth_date' => 3, 'company_id' => 0]];

        $diff = (new LearningDiffer)->diff(LearningKind::CardDav, $previous, $current);

        $this->assertTrue($diff['changed']);
        $this->assertSame(['birth_date'], $diff['newly_used_fields']);
        $this->assertSame(['company_id'], $diff['no_longer_used_fields']);
        $this->assertTrue($diff['reachability_changed']);
    }

    public function test_imports_diff_detects_new_format_keys_and_new_drafts(): void
    {
        $previous = ['formats' => ['properties' => []], 'draft_count' => 0];
        $current = ['formats' => ['properties' => [], 'units' => []], 'draft_count' => 1];

        $diff = (new LearningDiffer)->diff(LearningKind::Imports, $previous, $current);

        $this->assertTrue($diff['changed']);
        $this->assertSame(['units'], $diff['new_format_keys']);
        $this->assertTrue($diff['new_drafts_present']);
    }
}
