<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Services\FileService;

final class DownloadController extends Controller
{
    /** GET /download/{id} — checks permissions, then hands out a short-lived signed URL. */
    public function request(string $id): Response
    {
        $file = FileService::find((int) $id) ?? throw new HttpException(404, 'الملف غير موجود');
        try {
            $link = FileService::issueLink($file, Auth::user(), $this->request->wantsJson());
        } catch (HttpException $e) {
            if ($e->status === 401 && !$this->request->wantsJson()) {
                Session::set('intended', '/game/' . $file['game_slug']);
                flash('error', $e->getMessage());
                return redirect('/login');
            }
            throw $e;
        }
        if ($this->request->wantsJson()) {
            return json_ok($link);
        }
        return Response::redirect($link['url']);
    }

    /** GET /dl/{id}/{name}?u=&e=&s= — streams with Range support. */
    public function stream(string $id, string $name = ''): Response
    {
        return FileService::streamSigned($this->request, (int) $id);
    }
}
