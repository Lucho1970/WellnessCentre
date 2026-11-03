<?php
declare(strict_types=1);
namespace Wellness\Service;

/** Prevent accidental credential serialization; the HTTP adapter alone reads cookieValue. */
final readonly class BrowserSessionGrant implements \JsonSerializable
{
    public function __construct(private string $secret, public array $metadata) {}
    public function cookieValue(): string { return $this->secret; }
    public function jsonSerialize(): array { return $this->metadata; }
    public function __debugInfo(): array { return ['metadata_keys'=>array_keys($this->metadata)]; }
}
