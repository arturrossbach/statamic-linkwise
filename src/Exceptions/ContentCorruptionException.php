<?php

namespace Arturrossbach\Linkwise\Exceptions;

use RuntimeException;

/**
 * Raised when ContentSafetyValidator detects an invariant violation that
 * would store visibly corrupt content on the user's entry. SafeEntrySaver
 * catches it BEFORE save — the corruption never reaches disk.
 *
 * If you see this exception in logs, it means a code path produced output
 * that the validator considers unsafe. Either:
 *   - There's a real bug somewhere upstream (the validator is the catch-all)
 *   - The validator is too strict and this is a false positive (rare —
 *     the rules only flag patterns that are objectively broken markdown
 *     or invalid Bard structure)
 *
 * Either way, the user's content is preserved and an alert is logged.
 */
class ContentCorruptionException extends RuntimeException
{
    /**
     * @param  string  $entryId   The entry that almost got corrupted.
     * @param  string  $field     Blueprint handle of the field carrying the violation.
     * @param  string  $reason    Human-readable description of the invariant violated.
     * @param  string  $excerpt   Up to ~120 chars of the offending content for diagnostics.
     */
    public function __construct(
        public readonly string $entryId,
        public readonly string $field,
        public readonly string $reason,
        public readonly string $excerpt = '',
    ) {
        $message = "Content safety check failed for entry {$entryId}, field '{$field}': {$reason}";
        if ($excerpt !== '') {
            $message .= ' — '.mb_substr($excerpt, 0, 120);
        }
        parent::__construct($message);
    }
}
