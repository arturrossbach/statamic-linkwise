<?php

namespace Arturrossbach\Linkwise\Links;

class BrokenLinkRecord
{
    public function __construct(
        public readonly string $postId,
        public readonly string $postTitle,
        public readonly string $url,
        public readonly string $anchorText,
        public readonly string $type, // 'internal' or 'external'
        public readonly ?int $statusCode,
        public readonly string $errorType, // 'not_found', 'forbidden', 'server_error', 'timeout', 'ssl_error', 'connection_failed', 'missing_entry'
        public readonly string $firstDetectedAt,
        public readonly string $lastCheckedAt,
        public readonly string $sentenceContext = '',
        public readonly bool $ignored = false,
    ) {}

    /** Return a copy of this record with the ignored flag set to the given value. */
    public function withIgnored(bool $ignored): self
    {
        return new self(
            postId: $this->postId,
            postTitle: $this->postTitle,
            url: $this->url,
            anchorText: $this->anchorText,
            type: $this->type,
            statusCode: $this->statusCode,
            errorType: $this->errorType,
            firstDetectedAt: $this->firstDetectedAt,
            lastCheckedAt: $this->lastCheckedAt,
            sentenceContext: $this->sentenceContext,
            ignored: $ignored,
        );
    }

    public function toArray(): array
    {
        return [
            'post_id' => $this->postId,
            'post_title' => $this->postTitle,
            'url' => $this->url,
            'anchor_text' => $this->anchorText,
            'type' => $this->type,
            'status_code' => $this->statusCode,
            'error_type' => $this->errorType,
            'first_detected_at' => $this->firstDetectedAt,
            'last_checked_at' => $this->lastCheckedAt,
            'sentence_context' => $this->sentenceContext,
            'ignored' => $this->ignored,
        ];
    }

    /**
     * @throws \InvalidArgumentException when required fields are missing.
     *   Loaders MUST catch and skip — one corrupt record can't break the
     *   whole report read.
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['post_id']) || ! is_string($data['post_id'])) {
            throw new \InvalidArgumentException('BrokenLinkRecord: missing required field "post_id"');
        }
        if (empty($data['url']) || ! is_string($data['url'])) {
            throw new \InvalidArgumentException('BrokenLinkRecord: missing required field "url"');
        }

        // Backwards-compat: old reports had only 'checked_at' — use it for both fields
        $firstDetected = $data['first_detected_at'] ?? $data['checked_at'] ?? '';
        $lastChecked = $data['last_checked_at'] ?? $data['checked_at'] ?? '';

        return new self(
            postId: $data['post_id'],
            postTitle: isset($data['post_title']) && is_string($data['post_title']) ? $data['post_title'] : '',
            url: $data['url'],
            anchorText: isset($data['anchor_text']) && is_string($data['anchor_text']) ? $data['anchor_text'] : '',
            type: isset($data['type']) && is_string($data['type']) ? $data['type'] : 'external',
            statusCode: isset($data['status_code']) && is_numeric($data['status_code']) ? (int) $data['status_code'] : null,
            errorType: isset($data['error_type']) && is_string($data['error_type']) ? $data['error_type'] : 'unknown',
            firstDetectedAt: is_string($firstDetected) ? $firstDetected : '',
            lastCheckedAt: is_string($lastChecked) ? $lastChecked : '',
            sentenceContext: isset($data['sentence_context']) && is_string($data['sentence_context']) ? $data['sentence_context'] : '',
            ignored: (bool) ($data['ignored'] ?? false),
        );
    }

    public function statusLabel(): string
    {
        return match ($this->errorType) {
            'not_found' => '404 Not Found',
            'forbidden' => $this->statusCode.' Forbidden',
            'server_error' => $this->statusCode.' Server Error',
            'timeout' => 'Timed Out',
            'ssl_error' => 'SSL Error',
            'connection_failed' => 'Server Unreachable',
            'missing_entry' => 'Deleted Entry',
            default => $this->statusCode ? (string) $this->statusCode : 'Unknown',
        };
    }
}
