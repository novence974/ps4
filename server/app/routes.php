<?php
declare(strict_types=1);

use App\Controllers\Admin\GamesController as AdminGames;
use App\Controllers\Admin\ManageController as Manage;
use App\Controllers\Admin\SystemController as Sys;
use App\Controllers\Api\AdminController as ApiAdmin;
use App\Controllers\Api\AuthController as ApiAuth;
use App\Controllers\Api\PublicController as ApiPublic;
use App\Controllers\Api\UploadController as ApiUpload;
use App\Controllers\Api\AdminSystemController as ApiSystem;
use App\Controllers\Api\UserController as ApiUser;
use App\Controllers\Web\AccountController as Account;
use App\Controllers\Web\AuthController as WebAuth;
use App\Controllers\Web\DownloadController as Download;
use App\Controllers\Web\SiteController as Site;

/** @var App\Core\Router $router */

// Signed, session-less downloads (Range / resume supported)
$router->get('/dl/{id:\d+}/{name}', [Download::class, 'stream']);

// =====================================================================  WEBSITE
$router->group(['middleware' => ['web']], static function ($r) {
    $r->get('/', [Site::class, 'home']);
    $r->get('/platforms', [Site::class, 'platforms']);
    $r->get('/platform/{slug}', [Site::class, 'platform']);
    $r->get('/games', [Site::class, 'browse']);
    $r->get('/categories', [Site::class, 'categories']);
    $r->get('/category/{slug}', [Site::class, 'category']);
    $r->get('/game/{slug}', [Site::class, 'game']);
    $r->get('/updates', [Site::class, 'updates']);

    $r->get('/login', [WebAuth::class, 'loginForm'], ['guest']);
    $r->post('/login', [WebAuth::class, 'login'], ['guest', 'throttle:login,20,300']);
    $r->get('/register', [WebAuth::class, 'registerForm'], ['guest']);
    $r->post('/register', [WebAuth::class, 'register'], ['guest', 'throttle:register,6,600']);
    $r->post('/logout', [WebAuth::class, 'logout']);
    $r->get('/forgot-password', [WebAuth::class, 'forgotForm']);
    $r->post('/forgot-password', [WebAuth::class, 'forgot'], ['throttle:forgot,5,900']);
    $r->get('/reset-password', [WebAuth::class, 'resetForm']);
    $r->post('/reset-password', [WebAuth::class, 'reset'], ['throttle:reset,10,900']);
    $r->get('/verify-email', [WebAuth::class, 'verify']);
    $r->post('/verify-email/resend', [WebAuth::class, 'resend'], ['auth', 'throttle:resend,3,900']);

    $r->get('/download/{id:\d+}', [Download::class, 'request']);
    $r->post('/favorites/{id:\d+}', [Account::class, 'toggleFavorite'], ['auth']);

    $r->group(['prefix' => '/account', 'middleware' => ['auth']], static function ($r) {
        $r->get('', [Account::class, 'index']);
        $r->get('/edit', [Account::class, 'edit']);
        $r->post('/profile', [Account::class, 'updateProfile']);
        $r->post('/avatar', [Account::class, 'updateAvatar']);
        $r->post('/avatar/remove', [Account::class, 'removeAvatar']);
        $r->post('/password', [Account::class, 'updatePassword']);
        $r->post('/preferences', [Account::class, 'preferences']);
        $r->get('/favorites', [Account::class, 'favorites']);
        $r->get('/history', [Account::class, 'history']);
        $r->post('/history/clear', [Account::class, 'clearHistory']);
        $r->get('/downloads', [Account::class, 'downloads']);
        $r->get('/updates', [Account::class, 'updates']);
        $r->get('/notifications', [Account::class, 'notifications']);
        $r->post('/notifications/read', [Account::class, 'readNotifications']);
        $r->get('/devices', [Account::class, 'devices']);
        $r->post('/devices/{id:\d+}/revoke', [Account::class, 'revokeDevice']);
    });

    // ------------------------------------------------------------------ ADMIN PANEL
    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function ($r) {
        $r->get('', [Sys::class, 'dashboard']);
        $r->get('/health', [Sys::class, 'health']);
        $r->post('/queue/run', [Sys::class, 'runQueue']);

        $r->get('/games', [AdminGames::class, 'index']);
        $r->get('/games/create', [AdminGames::class, 'create']);
        $r->post('/games', [AdminGames::class, 'store']);
        $r->post('/games/bulk', [AdminGames::class, 'bulk']);
        $r->get('/games/check-duplicates', [AdminGames::class, 'checkDuplicates']);
        $r->get('/games/{id:\d+}/edit', [AdminGames::class, 'edit']);
        $r->post('/games/{id:\d+}', [AdminGames::class, 'update']);
        $r->post('/games/{id:\d+}/delete', [AdminGames::class, 'destroy']);
        $r->post('/games/{id:\d+}/publish', [AdminGames::class, 'publish']);
        $r->post('/games/{id:\d+}/image/{kind:cover|banner}', [AdminGames::class, 'image']);
        $r->post('/games/{id:\d+}/screenshots', [AdminGames::class, 'screenshots']);
        $r->post('/games/{id:\d+}/screenshots/reorder', [AdminGames::class, 'reorderScreenshots']);
        $r->post('/games/{id:\d+}/screenshots/{img:\d+}/delete', [AdminGames::class, 'deleteScreenshot']);
        $r->post('/games/{id:\d+}/trailer/remove', [AdminGames::class, 'removeTrailer']);
        $r->post('/games/{id:\d+}/versions', [AdminGames::class, 'saveVersion']);
        $r->post('/games/{id:\d+}/versions/{vid:\d+}', [AdminGames::class, 'saveVersion']);
        $r->post('/games/{id:\d+}/versions/{vid:\d+}/delete', [AdminGames::class, 'deleteVersion']);
        $r->post('/games/{id:\d+}/links', [AdminGames::class, 'saveLink']);
        $r->post('/games/{id:\d+}/links/{lid:\d+}', [AdminGames::class, 'saveLink']);
        $r->post('/games/{id:\d+}/links/{lid:\d+}/delete', [AdminGames::class, 'deleteLink']);
        $r->post('/games/{id:\d+}/compat', [AdminGames::class, 'saveCompat']);
        $r->post('/games/{id:\d+}/compat/{cid:\d+}/delete', [AdminGames::class, 'deleteCompat']);
        $r->post('/files/{fid:\d+}', [AdminGames::class, 'updateFile']);
        $r->post('/files/{fid:\d+}/delete', [AdminGames::class, 'deleteFile']);
        $r->post('/files/{fid:\d+}/verify', [AdminGames::class, 'verifyFile']);
        $r->get('/duplicates', [AdminGames::class, 'duplicates']);
        $r->get('/import', [AdminGames::class, 'import']);
        $r->post('/import', [AdminGames::class, 'importPreview']);
        $r->post('/import/commit', [AdminGames::class, 'importCommit']);
        $r->get('/import/template.{format:csv|json}', [AdminGames::class, 'importTemplate']);

        $r->get('/platforms', [Manage::class, 'platforms']);
        $r->get('/platforms/create', [Manage::class, 'platformForm']);
        $r->post('/platforms', [Manage::class, 'platformSave']);
        $r->get('/platforms/{id:\d+}/edit', [Manage::class, 'platformForm']);
        $r->post('/platforms/{id:\d+}', [Manage::class, 'platformSave']);
        $r->post('/platforms/{id:\d+}/image/{kind:logo|image}', [Manage::class, 'platformImage']);
        $r->get('/categories', [Manage::class, 'categories']);
        $r->post('/categories', [Manage::class, 'categorySave']);
        $r->post('/categories/{id:\d+}', [Manage::class, 'categorySave']);
        $r->post('/categories/{id:\d+}/delete', [Manage::class, 'categoryDelete']);

        $r->get('/users', [Manage::class, 'users']);
        $r->post('/users/create', [Manage::class, 'userCreate']);
        $r->get('/users/{id:\d+}', [Manage::class, 'showUser']);
        $r->post('/users/{id:\d+}', [Manage::class, 'userUpdate']);
        $r->post('/users/{id:\d+}/delete', [Manage::class, 'userDelete']);
        $r->get('/roles', [Manage::class, 'roles']);
        $r->post('/roles', [Manage::class, 'rolesSave']);

        $r->get('/updates', [Manage::class, 'updates']);
        $r->post('/updates', [Manage::class, 'updateSave']);
        $r->post('/updates/{id:\d+}', [Manage::class, 'updateSave']);
        $r->post('/updates/{id:\d+}/delete', [Manage::class, 'updateDelete']);
        $r->get('/notifications', [Manage::class, 'notifications']);
        $r->post('/notifications', [Manage::class, 'notificationSend']);
        $r->post('/notifications/{id:\d+}/delete', [Manage::class, 'notificationDelete']);

        $r->get('/uploads', [Sys::class, 'uploads']);
        $r->get('/files', [Sys::class, 'files']);
        $r->get('/downloads', [Sys::class, 'downloads']);
        $r->get('/storage', [Sys::class, 'storage']);
        $r->post('/storage/{action:cleanup|orphans|cache}', [Sys::class, 'storageAction']);
        $r->get('/api', [Sys::class, 'api']);
        $r->post('/api', [Sys::class, 'apiSave']);
        $r->post('/api/tokens/{id:\d+}/revoke', [Sys::class, 'revokeToken']);
        $r->get('/security', [Sys::class, 'security']);
        $r->post('/security', [Sys::class, 'securitySave']);
        $r->post('/security/unlock/{id:\d+}', [Sys::class, 'unlock']);
        $r->get('/logs', [Sys::class, 'logs']);
        $r->get('/logs/errors', [Sys::class, 'errors']);
        $r->get('/backups', [Sys::class, 'backups']);
        $r->post('/backups', [Sys::class, 'backupCreate']);
        $r->post('/backups/settings', [Sys::class, 'backupSettings']);
        $r->get('/backups/{name:[A-Za-z0-9._\-]+}/download', [Sys::class, 'backupDownload']);
        $r->post('/backups/{name:[A-Za-z0-9._\-]+}/restore', [Sys::class, 'backupRestore']);
        $r->post('/backups/{name:[A-Za-z0-9._\-]+}/delete', [Sys::class, 'backupDelete']);
        $r->get('/settings', [Sys::class, 'settings']);
        $r->post('/settings', [Sys::class, 'settingsSave']);
        $r->post('/settings/mail-test', [Sys::class, 'mailTest']);
        $r->post('/settings/music', [Sys::class, 'musicUpload']);
        $r->post('/settings/music/remove', [Sys::class, 'musicRemove']);
    });
});

// =====================================================================  REST API v1
$router->group(['prefix' => '/api/v1', 'middleware' => ['api']], static function ($r) {
    $r->get('/status', [ApiPublic::class, 'status']);
    $r->get('/platforms', [ApiPublic::class, 'platforms']);
    $r->get('/platforms/{slug}', [ApiPublic::class, 'platform']);
    $r->get('/categories', [ApiPublic::class, 'categories']);
    $r->get('/games', [ApiPublic::class, 'games']);
    $r->get('/games/{id}', [ApiPublic::class, 'game']);
    $r->get('/games/{id}/related', [ApiPublic::class, 'related']);
    $r->get('/search', [ApiPublic::class, 'search']);
    $r->get('/updates', [ApiPublic::class, 'updates']);

    $r->post('/auth/login', [ApiAuth::class, 'login'], ['throttle:api-login,15,300']);
    $r->post('/auth/register', [ApiAuth::class, 'register'], ['throttle:api-register,6,600']);
    $r->post('/auth/forgot-password', [ApiAuth::class, 'forgot'], ['throttle:api-forgot,5,900']);
    $r->post('/auth/reset-password', [ApiAuth::class, 'reset'], ['throttle:api-reset,10,900']);
    $r->post('/auth/logout', [ApiAuth::class, 'logout'], ['auth']);
    $r->post('/auth/resend-verification', [ApiAuth::class, 'resendVerification'], ['auth', 'throttle:api-resend,3,900']);

    $r->post('/games/{id:\d+}/files/{fileId:\d+}/link', [ApiUser::class, 'downloadLink']);
    $r->post('/links/{id:\d+}/click', [ApiPublic::class, 'linkClick'], ['throttle:link-click,60,60']);

    $r->group(['prefix' => '/user', 'middleware' => ['auth']], static function ($r) {
        $r->get('/profile', [ApiUser::class, 'profile']);
        $r->put('/profile', [ApiUser::class, 'updateProfile']);
        $r->post('/avatar', [ApiUser::class, 'avatar']);
        $r->put('/password', [ApiUser::class, 'password']);
        $r->put('/preferences', [ApiUser::class, 'preferences']);
        $r->get('/favorites', [ApiUser::class, 'favorites']);
        $r->post('/favorites', [ApiUser::class, 'addFavorite']);
        $r->delete('/favorites/{gameId:\d+}', [ApiUser::class, 'removeFavorite']);
        $r->get('/downloads', [ApiUser::class, 'downloads']);
        $r->get('/history', [ApiUser::class, 'history']);
        $r->delete('/history', [ApiUser::class, 'clearHistory']);
        $r->get('/updates', [ApiUser::class, 'updates']);
        $r->get('/notifications', [ApiUser::class, 'notifications']);
        $r->post('/notifications/read', [ApiUser::class, 'readNotifications']);
        $r->get('/tokens', [ApiUser::class, 'tokens']);
        $r->delete('/tokens/{id:\d+}', [ApiUser::class, 'revokeToken']);
    });

    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function ($r) {
        $r->get('/stats', [ApiAdmin::class, 'stats']);
        $r->get('/health', [ApiAdmin::class, 'health']);

        $r->get('/games', [ApiAdmin::class, 'games']);
        $r->post('/games', [ApiAdmin::class, 'createGame']);
        $r->get('/games/check-duplicates', [ApiAdmin::class, 'checkDuplicates']);
        $r->get('/games/{id:\d+}', [ApiAdmin::class, 'game']);
        $r->put('/games/{id:\d+}', [ApiAdmin::class, 'updateGame']);
        $r->delete('/games/{id:\d+}', [ApiAdmin::class, 'deleteGame']);
        $r->post('/games/{id:\d+}/publish', [ApiAdmin::class, 'publish']);
        $r->post('/games/{id:\d+}/cover', [ApiAdmin::class, 'cover']);
        $r->post('/games/{id:\d+}/banner', [ApiAdmin::class, 'banner']);
        $r->post('/games/{id:\d+}/screenshots', [ApiAdmin::class, 'screenshot']);
        $r->delete('/games/{id:\d+}/screenshots/{imageId:\d+}', [ApiAdmin::class, 'deleteScreenshot']);
        $r->get('/games/{id:\d+}/versions', [ApiAdmin::class, 'versions']);
        $r->post('/games/{id:\d+}/versions', [ApiAdmin::class, 'saveVersion']);
        $r->put('/games/{id:\d+}/versions/{versionId:\d+}', [ApiAdmin::class, 'saveVersion']);
        $r->delete('/games/{id:\d+}/versions/{versionId:\d+}', [ApiAdmin::class, 'deleteVersion']);
        $r->get('/games/{id:\d+}/links', [ApiAdmin::class, 'links']);
        $r->post('/games/{id:\d+}/links', [ApiAdmin::class, 'saveLink']);
        $r->put('/games/{id:\d+}/links/{linkId:\d+}', [ApiAdmin::class, 'saveLink']);
        $r->delete('/games/{id:\d+}/links/{linkId:\d+}', [ApiAdmin::class, 'deleteLink']);
        $r->post('/games/{id:\d+}/compat', [ApiAdmin::class, 'saveCompat']);
        $r->delete('/games/{id:\d+}/compat/{compatId:\d+}', [ApiAdmin::class, 'deleteCompat']);
        $r->get('/games/{id:\d+}/files', [ApiAdmin::class, 'files']);
        $r->put('/files/{fileId:\d+}', [ApiAdmin::class, 'updateFile']);
        $r->delete('/files/{fileId:\d+}', [ApiAdmin::class, 'deleteFile']);
        $r->post('/files/{fileId:\d+}/verify', [ApiAdmin::class, 'verifyFile']);
        $r->get('/duplicates', [ApiAdmin::class, 'duplicates']);
        $r->post('/import', [ApiAdmin::class, 'import']);

        $r->get('/platforms', [ApiAdmin::class, 'platforms']);
        $r->post('/platforms', [ApiAdmin::class, 'savePlatform']);
        $r->put('/platforms/{id:\d+}', [ApiAdmin::class, 'savePlatform']);
        $r->post('/platforms/{id:\d+}/{kind:logo|image}', [ApiAdmin::class, 'platformImage']);
        $r->post('/categories', [ApiAdmin::class, 'saveCategory']);
        $r->put('/categories/{id:\d+}', [ApiAdmin::class, 'saveCategory']);
        $r->delete('/categories/{id:\d+}', [ApiAdmin::class, 'deleteCategory']);

        $r->get('/users', [ApiAdmin::class, 'users']);
        $r->get('/users/{id:\d+}', [ApiAdmin::class, 'showUser']);
        $r->put('/users/{id:\d+}', [ApiAdmin::class, 'updateUser']);
        $r->delete('/users/{id:\d+}', [ApiAdmin::class, 'deleteUser']);

        $r->get('/updates', [ApiAdmin::class, 'updates']);
        $r->post('/updates', [ApiAdmin::class, 'saveUpdate']);
        $r->put('/updates/{id:\d+}', [ApiAdmin::class, 'saveUpdate']);
        $r->delete('/updates/{id:\d+}', [ApiAdmin::class, 'deleteUpdate']);
        $r->get('/notifications', [ApiAdmin::class, 'notifications']);
        $r->post('/notifications', [ApiAdmin::class, 'sendNotification']);

        $r->post('/uploads/init', [ApiUpload::class, 'init']);
        $r->post('/uploads/chunk', [ApiUpload::class, 'chunk']);
        $r->post('/uploads/complete', [ApiUpload::class, 'complete']);
        $r->get('/uploads', [ApiUpload::class, 'index']);
        $r->get('/uploads/{id:[a-f0-9]{32}}', [ApiUpload::class, 'status']);
        $r->delete('/uploads/{id:[a-f0-9]{32}}', [ApiUpload::class, 'cancel']);

        $r->get('/downloads', [ApiAdmin::class, 'downloads']);
        $r->get('/storage', [ApiAdmin::class, 'storage']);
        $r->get('/logs', [ApiAdmin::class, 'logs']);
        $r->get('/logs/summary', [ApiAdmin::class, 'logsSummary']);

        // everything else the web panel can do (used by the Android admin app)
        $r->post('/games/bulk', [ApiSystem::class, 'bulkGames']);
        $r->post('/games/{id:\d+}/screenshots/reorder', [ApiSystem::class, 'reorderScreenshots']);
        $r->delete('/games/{id:\d+}/trailer', [ApiSystem::class, 'removeTrailer']);
        $r->delete('/notifications/{id:\d+}', [ApiSystem::class, 'deleteNotification']);
        $r->post('/users', [ApiSystem::class, 'createUser']);
        $r->get('/roles', [ApiSystem::class, 'roles']);
        $r->put('/roles', [ApiSystem::class, 'saveRoles']);
        $r->get('/tokens', [ApiSystem::class, 'tokens']);
        $r->delete('/tokens/{id:\d+}', [ApiSystem::class, 'revokeToken']);
        $r->get('/security', [ApiSystem::class, 'security']);
        $r->get('/settings', [ApiSystem::class, 'settings']);
        $r->put('/settings/{group:general|security|api|backups}', [ApiSystem::class, 'saveSettings']);
        $r->post('/settings/mail-test', [ApiSystem::class, 'mailTest']);
        $r->delete('/settings/music', [ApiSystem::class, 'musicRemove']);
        $r->get('/files', [ApiSystem::class, 'files']);
        $r->get('/storage/orphans', [ApiSystem::class, 'orphans']);
        $r->post('/storage/{action:cleanup|orphans|cache}', [ApiSystem::class, 'storageAction']);
        $r->post('/queue/run', [ApiSystem::class, 'runQueue']);
        $r->get('/logs/errors', [ApiSystem::class, 'errors']);
        $r->get('/backups', [ApiSystem::class, 'backups']);
        $r->post('/backups', [ApiSystem::class, 'createBackup']);
        $r->get('/backups/{name:[A-Za-z0-9._\-]+}/download', [ApiSystem::class, 'downloadBackup']);
        $r->post('/backups/{name:[A-Za-z0-9._\-]+}/restore', [ApiSystem::class, 'restoreBackup']);
        $r->delete('/backups/{name:[A-Za-z0-9._\-]+}', [ApiSystem::class, 'deleteBackup']);
    });
});
