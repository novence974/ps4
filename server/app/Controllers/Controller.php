<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    protected function user(): ?array
    {
        return Auth::user();
    }

    protected function requireUser(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }

    protected function authorize(string ...$permissions): void
    {
        foreach ($permissions as $p) {
            if (Auth::can($p)) {
                return;
            }
        }
        throw new HttpException(403);
    }

    protected function page(): int
    {
        return max(1, min(100000, $this->request->int('page', 1)));
    }

    protected function perPage(int $default = 24, int $max = 100): int
    {
        return max(1, min($max, $this->request->int('per_page', $default)));
    }

    /** JSON for fetch() callers, redirect + toast for classic form posts. */
    protected function done(string $message, string $redirect = '', mixed $data = null): Response
    {
        if ($this->request->wantsJson()) {
            return json_ok($data ?? ['message' => $message]);
        }
        flash('success', $message);
        return $redirect !== '' ? redirect($redirect) : back();
    }

    protected function uploadedTmp(string $field): array
    {
        $file = $this->request->file($field);
        if (!$file) {
            throw \App\Core\ValidationException::single($field, 'لم يتم اختيار ملف');
        }
        if (!$file->isValid()) {
            throw \App\Core\ValidationException::single($field, $file->errorMessage());
        }
        return [$file->tmpPath, $file->name];
    }
}
