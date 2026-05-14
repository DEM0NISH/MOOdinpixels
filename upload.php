<?php
/**
 * MoodinPixels — Admin Photo Upload Handler
 * Place this file in the same directory as index.html on your server.
 * 
 * Category → folder mapping matches the site's existing folder structure.
 */

// ── CONFIG ──────────────────────────────────────────────────────────────────
define('ADMIN_PASSWORD', 'moodin2025');   // must match the password in index.html
define('MAX_FILE_SIZE',  20 * 1024 * 1024); // 20 MB per file
define('ALLOWED_TYPES',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// Category slug → server folder name
$CAT_FOLDERS = [
    'concerts'   => 'Concert',
    'dance'      => 'Dance',
    'dj'         => 'DJ',
    'fashion'    => 'Fashion',
    'music'      => 'Music',
    'photoshoot' => 'Photoshoot',
    'sports'     => 'Sports',
    'wildlife'   => 'Wildlife',
];
// ────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten to your domain in production

// ── Helper: send JSON response ───────────────────────────────────────────────
function respond($ok, $message, $data = []) {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $data));
    exit;
}

// ── Only accept POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.');
}

// ── Auth check ───────────────────────────────────────────────────────────────
$pw = $_POST['password'] ?? '';
if ($pw !== ADMIN_PASSWORD) {
    http_response_code(403);
    respond(false, 'Unauthorized.');
}

// ── Action routing ───────────────────────────────────────────────────────────
$action = $_POST['action'] ?? 'upload';

// ── LIST photos in a category ────────────────────────────────────────────────
if ($action === 'list') {
    global $CAT_FOLDERS;
    $cat = $_POST['category'] ?? '';
    if (!isset($CAT_FOLDERS[$cat])) respond(false, 'Unknown category.');
    $folder = __DIR__ . '/' . $CAT_FOLDERS[$cat];
    if (!is_dir($folder)) respond(true, 'ok', ['photos' => []]);
    $files = array_values(array_filter(scandir($folder), function($f) use ($folder) {
        return !in_array($f, ['.', '..']) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $f);
    }));
    $photos = array_map(function($f) use ($cat, $CAT_FOLDERS) {
        return [
            'id'  => $cat . '_' . $f,
            'src' => $CAT_FOLDERS[$cat] . '/' . $f,
            'name'=> $f,
        ];
    }, $files);
    respond(true, 'ok', ['photos' => $photos]);
}

// ── DELETE a photo ───────────────────────────────────────────────────────────
if ($action === 'delete') {
    global $CAT_FOLDERS;
    $cat      = $_POST['category'] ?? '';
    $filename = basename($_POST['filename'] ?? '');
    if (!isset($CAT_FOLDERS[$cat])) respond(false, 'Unknown category.');
    if (!$filename) respond(false, 'No filename.');
    $path = __DIR__ . '/' . $CAT_FOLDERS[$cat] . '/' . $filename;
    if (!file_exists($path)) respond(false, 'File not found.');
    // Safety: ensure path is inside the expected folder
    $realPath   = realpath($path);
    $realFolder = realpath(__DIR__ . '/' . $CAT_FOLDERS[$cat]);
    if (!$realPath || strpos($realPath, $realFolder) !== 0) {
        respond(false, 'Invalid path.');
    }
    unlink($realPath);
    respond(true, 'Photo deleted.');
}

// ── UPLOAD photos ────────────────────────────────────────────────────────────
if ($action === 'upload') {
    global $CAT_FOLDERS;
    $cat = $_POST['category'] ?? '';
    if (!isset($CAT_FOLDERS[$cat])) respond(false, 'Unknown category.');

    if (empty($_FILES['photos'])) respond(false, 'No files received.');

    $folder = __DIR__ . '/' . $CAT_FOLDERS[$cat];

    // Create folder if it doesn't exist
    if (!is_dir($folder)) {
        if (!mkdir($folder, 0755, true)) {
            respond(false, 'Could not create folder: ' . $CAT_FOLDERS[$cat]);
        }
    }

    $uploaded = [];
    $errors   = [];

    // Normalise $_FILES array so we always have an array of files
    $files = $_FILES['photos'];
    $isMultiple = is_array($files['name']);
    $count = $isMultiple ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
        $name    = $isMultiple ? $files['name'][$i]     : $files['name'];
        $type    = $isMultiple ? $files['type'][$i]     : $files['type'];
        $tmpName = $isMultiple ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error   = $isMultiple ? $files['error'][$i]    : $files['error'];
        $size    = $isMultiple ? $files['size'][$i]     : $files['size'];

        // Upload error
        if ($error !== UPLOAD_ERR_OK) { $errors[] = "$name: upload error $error"; continue; }
        // Size check
        if ($size > MAX_FILE_SIZE)    { $errors[] = "$name: too large (max 20 MB)"; continue; }
        // MIME check via finfo (more reliable than $_FILES['type'])
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
        if (!in_array($mime, ALLOWED_TYPES)) { $errors[] = "$name: unsupported type ($mime)"; continue; }

        // Build a safe, unique filename: keep original name but sanitise it
        $ext       = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $baseName  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $safeBase  = substr($baseName, 0, 60);
        $unique    = $safeBase . '_' . substr(uniqid(), -6) . '.' . $ext;
        $dest      = $folder . '/' . $unique;

        if (move_uploaded_file($tmpName, $dest)) {
            $uploaded[] = [
                'id'   => $cat . '_' . $unique,
                'src'  => $CAT_FOLDERS[$cat] . '/' . $unique,
                'name' => $unique,
            ];
        } else {
            $errors[] = "$name: could not save file (check folder permissions).";
        }
    }

    if (empty($uploaded) && !empty($errors)) {
        respond(false, implode('; ', $errors));
    }

    respond(true, count($uploaded) . ' photo(s) uploaded.', [
        'uploaded' => $uploaded,
        'errors'   => $errors,
    ]);
}

respond(false, 'Unknown action.');
