<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Response;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): Response
    {
        return json_ok($data, $meta, $status);
    }

    protected function paginated(array $result, callable $map, array $extraMeta = []): Response
    {
        return json_ok(array_map($map, $result['items']), [
            'page' => (int) $result['page'], 'per_page' => (int) $result['per_page'],
            'total' => (int) $result['total'], 'pages' => (int) $result['pages'],
        ] + $extraMeta);
    }

    /** Multipart "file" field or a chunked upload id that already finished. */
    protected function incomingImage(): array
    {
        return $this->uploadedTmp('file');
    }
}
