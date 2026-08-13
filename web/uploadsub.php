<?php

/**
 * Manager/admin overlay subtitle upload: POST id + file + csrf.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../subs.php';

mwRequireRole('manager');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
if (!mwCsrfCheck($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Bad CSRF');
}

$id = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;
$file = $_FILES['sub'] ?? null;
if ($id <= 0 || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Bad upload');
}
if (($file['size'] ?? 0) < 1 || $file['size'] > 2_097_152) {
    http_response_code(400);
    exit('File too large (2MB max)');
}

$orig = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$tmp = (string)$file['tmp_name'];
if (!in_array($ext, ['srt', 'vtt', 'sub'], true)) {
    http_response_code(400);
    exit('Need .srt, .vtt, or .sub');
}

$row = mwAuthDb()->querySingle('SELECT is_deleted FROM videos WHERE id = ' . $id, true);
if (!$row || !empty($row['is_deleted'])) {
    http_response_code(404);
    exit('Not found');
}

$dir = rtrim(str_replace('\\', '/', MW_SUBS_DIR), '/') . '/' . $id;
if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
    http_response_code(500);
    exit('Cannot write subs dir');
}
@chmod($dir, 0777);

$meta = subClassify($tmp, (string)pathinfo($orig, PATHINFO_FILENAME));
$stem = ($meta['lang'] === 'bg' ? 'bg' : 'und') . ($meta['cvetni'] ? '-cvetni' : '');
foreach (['srt', 'vtt', 'sub'] as $e) {
    if ($e !== $ext) @unlink($dir . '/' . $stem . '.' . $e);
}
if (!move_uploaded_file($tmp, $dir . '/' . $stem . '.' . $ext)) {
    http_response_code(500);
    exit('Save failed');
}

header('Location: ' . MW_BASE_URL . '?view=' . $id);
exit;
