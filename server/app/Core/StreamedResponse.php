<?php
declare(strict_types=1);

namespace App\Core;

/** Response whose body is produced by a callback (large file downloads). Never compressed or buffered. */
final class StreamedResponse extends Response
{
    /** @var callable */
    private $callback;

    public function __construct(callable $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
        $this->callback = $callback;
    }

    public function send(bool $headOnly = false): void
    {
        $this->sendHeaders();
        if (!$headOnly) {
            ($this->callback)();
        }
    }
}
