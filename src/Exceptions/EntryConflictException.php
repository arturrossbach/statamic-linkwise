<?php

namespace Arturrossbach\Linkwise\Exceptions;

use RuntimeException;

class EntryConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $entryTitle,
    ) {
        parent::__construct(
            "Entry \"{$entryTitle}\" was modified by another user. Please reload and try again."
        );
    }
}
