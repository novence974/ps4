<?php
declare(strict_types=1);

namespace App\Core;

class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors, string $message = 'البيانات المدخلة غير صحيحة')
    {
        parent::__construct($message, 422);
    }

    /** First field message (readonly arrays cannot be passed to reset()). */
    public function first(): string
    {
        return (string) (array_values($this->errors)[0] ?? $this->getMessage());
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}
