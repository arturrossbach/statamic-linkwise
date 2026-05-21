<?php

namespace Arturrossbach\Linkwise\Tests\Unit;

use Arturrossbach\Linkwise\AutoLink\AutoLinkManager;
use Arturrossbach\Linkwise\AutoLink\AutoLinkRule;
use Arturrossbach\Linkwise\Tests\TestCase;

/**
 * Truth table for AutoLinkManager::findDuplicate().
 *
 * Two binary inputs per side (existing + incoming): caseSensitive ∈ {F, T},
 * keyword equality ∈ {same-cs, same-ci, different}. Each cell of the matrix
 * gets its own test so a regression on any combination is loud.
 *
 * Rule we encode:
 *   - Both case-sensitive  → exact (===) match is a duplicate.
 *   - Otherwise            → case-insensitive match is a duplicate.
 */
class AutoLinkManagerDuplicateTest extends TestCase
{
    private function rule(string $keyword, bool $caseSensitive): AutoLinkRule
    {
        return new AutoLinkRule(
            id: 'r-'.$keyword.'-'.($caseSensitive ? 'cs' : 'ci'),
            keyword: $keyword,
            url: 'https://example.com',
            targetEntryId: null,
            caseSensitive: $caseSensitive,
        );
    }

    // --- Both case-INsensitive ---

    public function test_both_ci_exact_keyword_is_duplicate(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'hund', false));
    }

    public function test_both_ci_different_case_is_duplicate(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'HUND', false));
    }

    public function test_both_ci_mixed_case_is_duplicate(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'Hund', false));
    }

    public function test_both_ci_different_word_is_ok(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'katze', false));
    }

    // --- Both case-SENSITIVE ---

    public function test_both_cs_exact_match_is_duplicate(): void
    {
        $existing = [$this->rule('Hund', true)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'Hund', true));
    }

    public function test_both_cs_uppercase_variant_is_ok(): void
    {
        // Regression: two case-sensitive rules with different casings
        // (e.g. "Hund" + "HUND") must coexist as distinct rules.
        $existing = [$this->rule('Hund', true)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'HUND', true));
    }

    public function test_both_cs_lowercase_variant_is_ok(): void
    {
        $existing = [$this->rule('Hund', true)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'hund', true));
    }

    public function test_both_cs_different_word_is_ok(): void
    {
        $existing = [$this->rule('Hund', true)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'Katze', true));
    }

    // --- Asymmetric: existing CS, incoming CI ---

    public function test_existing_cs_incoming_ci_same_lower_is_duplicate(): void
    {
        // Incoming case-insensitive would shadow the existing case-sensitive rule.
        $existing = [$this->rule('Hund', true)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'hund', false));
    }

    public function test_existing_cs_incoming_ci_different_word_is_ok(): void
    {
        $existing = [$this->rule('Hund', true)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'Katze', false));
    }

    // --- Asymmetric: existing CI, incoming CS ---

    public function test_existing_ci_incoming_cs_same_lower_is_duplicate(): void
    {
        // Existing case-insensitive already matches all variants → conflict.
        $existing = [$this->rule('hund', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'Hund', true));
    }

    public function test_existing_ci_incoming_cs_different_word_is_ok(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, 'Katze', true));
    }

    // --- Whitespace handling ---

    public function test_keyword_is_trimmed_before_compare(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, '  hund  ', false));
    }

    public function test_empty_keyword_returns_null(): void
    {
        $existing = [$this->rule('hund', false)];
        $this->assertNull(AutoLinkManager::findDuplicate($existing, '', false));
        $this->assertNull(AutoLinkManager::findDuplicate($existing, '   ', false));
    }

    // --- Empty corpus ---

    public function test_no_existing_rules_returns_null(): void
    {
        $this->assertNull(AutoLinkManager::findDuplicate([], 'hund', false));
        $this->assertNull(AutoLinkManager::findDuplicate([], 'Hund', true));
    }

    // --- Multi-rule corpus, the conflicting one is returned ---

    public function test_returns_specific_conflicting_rule(): void
    {
        $rules = [
            $this->rule('hund', false),
            $this->rule('katze', false),
            $this->rule('Hund', true),
        ];
        $conflict = AutoLinkManager::findDuplicate($rules, 'KATZE', false);
        $this->assertNotNull($conflict);
        $this->assertSame('katze', $conflict->keyword);
    }

    // --- Unicode lowercasing works (German umlauts, etc.) ---

    public function test_case_insensitive_compare_honors_unicode(): void
    {
        $existing = [$this->rule('über', false)];
        $this->assertNotNull(AutoLinkManager::findDuplicate($existing, 'ÜBER', false));
    }
}
