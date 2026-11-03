<?php
declare(strict_types=1);
namespace Wellness\Http;

/** Transport instructions only. Application adapter emits headers after handle returns. */
final readonly class BrowserSessionResponse implements \JsonSerializable
{
    public function __construct(public array $body, private array $responseHeaders) {}
    public function headers(): array { return $this->responseHeaders; }
    public function jsonSerialize(): array { return ['data'=>$this->body]; }
    public function __debugInfo(): array { return ['body_keys'=>array_keys($this->body)]; }
}
