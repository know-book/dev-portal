<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SecretStoreException extends RuntimeException
{
    /** @param array<string, bool|int|string|null> $diagnosticContext */
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $diagnosticContext = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, bool|int|string|null> */
    public function context(): array
    {
        return $this->diagnosticContext;
    }

    public function report(): bool
    {
        Log::channel('stderr')->error(
            'Vault integration failure: {message}',
            [...$this->diagnosticContext, 'message' => $this->getMessage()],
        );

        return true;
    }
}
