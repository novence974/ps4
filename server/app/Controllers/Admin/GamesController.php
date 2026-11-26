<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Api\AdminController as ApiAdmin;
use App\Controllers\Controller;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\CatalogService;
use App\Services\FileService;
use App\Services\GameService;
use App\Services\ImportService;
use App\Services\UploadService;

final class GamesController extends Controller
{
    private function render(string $tpl, array $data): Response
    {
        return view('admin/' . $tpl, $data, 'layouts/admin');
    }

    public function index(): Response
    {
        $q = $this->request->query;
        $result = GameService::search($q + ['sort' => $q['sort'] ?? 'updated'], $this->page(), 30, true);
        return $this->render('games/index', ['result' => $result, 'filters' => $q, 'platforms' => CatalogService::platforms(false),
            'categories' => CatalogService::categories(), 'title' => 'إدارة الألعاب']);
    }

    public function create(): Response
    {
        $this->authorize('games.create');
        return $this->render('games/form', ['game' => null, 'platforms' => CatalogService::platforms(false), 'categories' => CatalogService::categories(),
            'title' => 'إضافة لعبة جديدة', 'tab' => 'details']);
    }

    public function store(): Response
    {
        $this->authorize('games.create');
        $g = GameService::save(null, $this->request->post);
        flash('success', 'تمت إضافة «' . $g['title'] . '». أكمل الآن رفع الغلاف والملفات.');
        return redirect('/admin/games/' . $g['id'] . '/edit?tab=media');
    }

    public function edit(string $id): Response
    {
        $game = GameService::findOrFail((int) $id);
        $uploads = DB::all("SELECT * FROM uploads WHERE game_id = ? AND status IN ('uploading','processing','failed') ORDER BY id DESC LIMIT 20", [(int) $id]);
        return $this->render('games/form', ['game' => $game, 'platforms' => CatalogService::platforms(false), 'categories' => CatalogService::categories(),
            'uploads' => $uploads, 'title' => 'تعديل: ' . $game['title'], 'tab' => (string) $this->request->query('tab', 'details'),
            'allowedExt' => UploadService::allowedGameExtensions()]);
    }

    public function update(string $id): Response
    {
        $this->authorize('games.edit');
        GameService::save((int) $id, $this->request->post);
        flash('success', 'تم حفظ التعديلات');
        return redirect('/admin/games/' . (int) $id . '/edit?tab=details');
    }

    public function destroy(string $id): Response
    {
        $this->authorize('games.delete');
        GameService::delete((int) $id);
        return $this->done('تم حذف اللعبة وملفاتها', '/admin/games');
    }

    public function publish(string $id): Response
    {
        $this->authorize('games.publish');
        $g = GameService::setPublished((int) $id, $this->request->bool('published'));
        return $this->done($g['is_published'] ? 'تم نشر اللعبة' : 'تم إخفاء اللعبة', '', ['published' => (bool) $g['is_published']]);
    }

    public function bulk(): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($this->request->post['ids'] ?? []))));
        $action = (string) $this->request->input('action');
        if (!$ids) {
            throw ValidationException::single('ids', 'اختر لعبة واحدة على الأقل');
        }
        $n = 0;
        foreach ($ids as $id) {
            match ($action) {
                'publish', 'hide' => [$this->authorize('games.publish'), GameService::setPublished($id, $action === 'publish')],
                'feature', 'unfeature' => [$this->authorize('games.publish'), GameService::save($id, ['is_featured' => $action === 'feature'], true, false)],
                'delete' => [$this->authorize('games.delete'), GameService::delete($id)],
                default => throw ValidationException::single('action', 'إجراء غير معروف'),
            };
            $n++;
        }
        return $this->done("تم تنفيذ الإجراء على {$n} لعبة", '/admin/games');
    }

    public function checkDuplicates(): Response
    {
        return (new ApiAdmin($this->request))->checkDuplicates();
    }

    public function duplicates(): Response
    {
        $this->authorize('games.edit');
        return $this->render('games/duplicates', ['groups' => ApiAdmin::duplicateGroups(), 'title' => 'الألعاب المكررة']);
    }

    // ------------------------------------------------------------------ media
    public function image(string $id, string $kind): Response
    {
        $this->authorize('media.upload');
        if ($this->request->str('url') !== '') {
            GameService::setImageUrl((int) $id, $kind === 'banner' ? 'banner' : 'cover', $this->request->str('url'));
            return $this->done($kind === 'banner' ? 'تم حفظ رابط الخلفية' : 'تم حفظ رابط الغلاف', '/admin/games/' . (int) $id . '/edit?tab=media');
        }
        [$tmp] = $this->uploadedTmp('file');
        GameService::setImage((int) $id, $kind === 'banner' ? 'banner' : 'cover', $tmp);
        return $this->done($kind === 'banner' ? 'تم تحديث صورة الخلفية' : 'تم تحديث الغلاف', '/admin/games/' . (int) $id . '/edit?tab=media');
    }

    public function screenshots(string $id): Response
    {
        $this->authorize('media.upload');
        if ($this->request->str('url') !== '') {
            GameService::addScreenshotUrl((int) $id, $this->request->str('url'));
            return $this->done('تمت إضافة الصورة بالرابط', '/admin/games/' . (int) $id . '/edit?tab=media');
        }
        $files = $this->request->fileList('files');
        if (!$files) {
            throw ValidationException::single('files', 'اختر صورة واحدة على الأقل');
        }
        $ok = 0;
        $errors = [];
        foreach ($files as $f) {
            try {
                if (!$f->isValid()) {
                    throw ValidationException::single('files', $f->errorMessage());
                }
                GameService::addScreenshot((int) $id, $f->tmpPath);
                $ok++;
            } catch (ValidationException $e) {
                $errors[] = $f->name . ': ' . $e->first();
            }
        }
        if ($errors) {
            flash('error', implode(' | ', $errors));
        }
        return $this->done("تمت إضافة {$ok} صورة", '/admin/games/' . (int) $id . '/edit?tab=media');
    }

    public function deleteScreenshot(string $id, string $imageId): Response
    {
        $this->authorize('media.upload');
        GameService::deleteScreenshot((int) $id, (int) $imageId);
        return $this->done('تم حذف الصورة', '/admin/games/' . (int) $id . '/edit?tab=media');
    }

    public function reorderScreenshots(string $id): Response
    {
        $this->authorize('media.upload');
        GameService::reorderScreenshots((int) $id, (array) $this->request->input('ids', []));
        return json_ok(['saved' => true]);
    }

    public function removeTrailer(string $id): Response
    {
        $this->authorize('games.edit');
        $g = GameService::findOrFail((int) $id);
        \App\Services\MediaService::deleteFile($g['trailer_url']);
        DB::update('games', ['trailer_url' => null, 'updated_at' => now()], 'id = ?', [(int) $id]);
        \App\Core\Cache::bump('games');
        return $this->done('تمت إزالة الفيديو', '/admin/games/' . (int) $id . '/edit?tab=media');
    }

    // ------------------------------------------------------------------ versions, links, compatibility, files
    public function saveVersion(string $id, ?string $vid = null): Response
    {
        $this->authorize('games.edit');
        GameService::saveVersion((int) $id, $vid ? (int) $vid : null, $this->request->post);
        return $this->done('تم حفظ الإصدار', '/admin/games/' . (int) $id . '/edit?tab=versions');
    }

    public function deleteVersion(string $id, string $vid): Response
    {
        $this->authorize('games.edit');
        GameService::deleteVersion((int) $id, (int) $vid);
        return $this->done('تم حذف الإصدار', '/admin/games/' . (int) $id . '/edit?tab=versions');
    }

    public function saveLink(string $id, ?string $lid = null): Response
    {
        $this->authorize('links.manage');
        GameService::saveLink((int) $id, $lid ? (int) $lid : null, $this->request->post);
        return $this->done('تم حفظ الرابط', '/admin/games/' . (int) $id . '/edit?tab=links');
    }

    public function deleteLink(string $id, string $lid): Response
    {
        $this->authorize('links.manage');
        GameService::deleteLink((int) $id, (int) $lid);
        return $this->done('تم حذف الرابط', '/admin/games/' . (int) $id . '/edit?tab=links');
    }

    public function saveCompat(string $id): Response
    {
        $this->authorize('games.edit');
        GameService::saveCompat((int) $id, $this->request->post);
        return $this->done('تم حفظ معلومات التوافق', '/admin/games/' . (int) $id . '/edit?tab=compat');
    }

    public function deleteCompat(string $id, string $cid): Response
    {
        $this->authorize('games.edit');
        GameService::deleteCompat((int) $id, (int) $cid);
        return $this->done('تم الحذف', '/admin/games/' . (int) $id . '/edit?tab=compat');
    }

    public function updateFile(string $fid): Response
    {
        $this->authorize('files.manage');
        $f = FileService::update((int) $fid, $this->request->post);
        return $this->done('تم حفظ بيانات الملف', '/admin/games/' . $f['game_id'] . '/edit?tab=files');
    }

    public function deleteFile(string $fid): Response
    {
        $this->authorize('files.manage');
        $f = FileService::find((int) $fid) ?? throw new HttpException(404);
        FileService::delete((int) $fid);
        return $this->done('تم حذف الملف من الخادم', '/admin/games/' . $f['game_id'] . '/edit?tab=files');
    }

    public function verifyFile(string $fid): Response
    {
        $this->authorize('files.manage');
        $r = FileService::verifyIntegrity((int) $fid);
        if (!$r['ok']) {
            flash('error', 'تحذير: بصمة SHA-256 للملف على القرص لا تطابق البصمة المسجلة!');
            return back();
        }
        return $this->done('الملف سليم ✓ SHA-256 مطابق');
    }

    // ------------------------------------------------------------------ bulk import
    public function import(): Response
    {
        $this->authorize('games.import');
        return $this->render('games/import', ['preview' => null, 'token' => null, 'title' => 'استيراد الألعاب بالجملة']);
    }

    public function importPreview(): Response
    {
        $this->authorize('games.import');
        [$tmp, $name] = $this->uploadedTmp('file');
        $token = ImportService::store($tmp, $name);
        Session::set('import_token', $token);
        $preview = ImportService::run($token, 'preview');
        return $this->render('games/import', ['preview' => $preview, 'token' => $token, 'filename' => $name, 'title' => 'معاينة الاستيراد']);
    }

    public function importCommit(): Response
    {
        $this->authorize('games.import');
        $token = $this->request->str('token');
        if ($token === '' || $token !== Session::get('import_token')) {
            throw ValidationException::single('token', 'انتهت جلسة الاستيراد، ارفع الملف مجدداً');
        }
        $res = ImportService::run($token, 'commit', $this->request->str('on_duplicate') ?: 'skip');
        Session::forget('import_token');
        flash($res['failed'] ? 'error' : 'success', sprintf('اكتمل الاستيراد: %d جديدة، %d محدثة، %d متجاهلة، %d فشلت%s',
            $res['created'], $res['updated'], $res['skipped'], $res['failed'], $res['errors'] ? ' — ' . implode(' | ', array_slice($res['errors'], 0, 3)) : ''));
        return redirect('/admin/games');
    }

    public function importTemplate(string $format): Response
    {
        $format = $format === 'json' ? 'json' : 'csv';
        return new Response(ImportService::template($format), 200, [
            'Content-Type' => $format === 'json' ? 'application/json; charset=UTF-8' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="games-template.' . $format . '"',
        ]);
    }
}
