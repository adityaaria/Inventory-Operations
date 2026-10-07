<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string|\Closure $body,
        private readonly int $statusCode = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->statusCode, [...$this->headers, ...$headers]);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->body instanceof \Closure) {
            ($this->body)();
        } else {
            echo $this->body;
        }
    }

    public function body(): string
    {
        if ($this->body instanceof \Closure) {
            ob_start();
            try {
                ($this->body)();
                return (string) ob_get_contents();
            } finally { ob_end_clean(); }
        }
        return $this->body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
