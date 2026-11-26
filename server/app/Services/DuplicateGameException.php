<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;

final class DuplicateGameException extends ValidationException
{
    public function __construct(public readonly array $duplicates)
    {
        $titles = implode('، ', array_map(static fn ($d) => $d['title'] . ' (#' . $d['id'] . ')', array_slice($duplicates, 0, 3)));
        $msg = 'توجد لعبة مطابقة على نفس الجهاز: ' . $titles . '. فعّل خيار «السماح بالتكرار» إن كانت إصداراً مختلفاً.';
        parent::__construct(['title' => $msg], $msg);
    }
}
