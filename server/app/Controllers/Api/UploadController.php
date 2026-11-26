<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Response;
use App\Services\UploadService;

/**
 * POST   /api/v1/admin/uploads/init       {purpose, filename, size, game_id?, file_type?, access_level?, sha256?}
 * POST   /api/v1/admin/uploads/chunk      body = raw bytes | multipart "chunk" | JSON {data_base64}
 *                                         headers X-Upload-Id, X-Chunk-Index, X-Chunk-Sha256 (or the same as query/form fields)
 * POST   /api/v1/admin/uploads/complete   {upload_id, sha256?}
 * GET    /api/v1/admin/uploads/{id}       status + received chunk indexes (resume)
 * DELETE /api/v1/admin/uploads/{id}       cancel
 */
final class UploadController extends ApiController
{
    public function init(): Response
    {
        $data = UploadService::init($this->request->all());
        return $this->ok($data, [], $data['resumed'] ? 200 : 201);
    }

    public function chunk(): Response
    {
        $id = (string) ($this->request->header('X-Upload-Id') ?? $this->request->query('upload_id') ?? $this->request->post['upload_id'] ?? $this->request->json()['upload_id'] ?? '');
        $index = $this->request->header('X-Chunk-Index') ?? $this->request->query('chunk_index') ?? $this->request->post['chunk_index'] ?? $this->request->json()['chunk_index'] ?? null;
        if (!is_numeric($index)) {
            throw \App\Core\ValidationException::single('chunk_index', 'رقم الجزء مطلوب');
        }
        return $this->ok(UploadService::receiveChunk($id, (int) $index, $this->request));
    }

    public function complete(): Response
    {
        $data = UploadService::complete($this->request->str('upload_id'), $this->request->str('sha256') ?: null);
        return $this->ok($data, [], $data['status'] === 'processing' ? 202 : 200);
    }

    public function status(string $id): Response
    {
        return $this->ok(UploadService::status($id));
    }

    public function cancel(string $id): Response
    {
        UploadService::cancel($id);
        return $this->ok(['cancelled' => true]);
    }

    public function index(): Response
    {
        $r = UploadService::list($this->request->query, $this->page(), $this->perPage(30));
        return $this->paginated($r, static fn ($u) => [
            'upload_id' => $u['uuid'], 'purpose' => $u['purpose'], 'status' => $u['status'], 'filename' => $u['original_name'],
            'size' => (int) $u['size_bytes'], 'received_bytes' => (int) $u['received_bytes'],
            'progress' => $u['size_bytes'] > 0 ? round($u['received_bytes'] / $u['size_bytes'] * 100, 1) : 0,
            'game' => $u['game_id'] ? ['id' => (int) $u['game_id'], 'title' => $u['game_title']] : null,
            'user' => $u['username'], 'error' => $u['error'], 'sha256' => $u['sha256'],
            'created_at' => iso_date($u['created_at']), 'updated_at' => iso_date($u['updated_at']),
        ]);
    }
}
