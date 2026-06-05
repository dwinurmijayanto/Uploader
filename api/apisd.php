<?php
/**
 * slicedrive_upload.php — SliceDrive Video Upload Proxy v3.0
 *
 * Mengadopsi pipeline aceimg (apis6.php):
 *   validate → probe → download → upload → verify
 *   + Debug Collector, Discovery Mode, ?url= GET param
 *
 * Mode 1 — URL Upload  : GET/POST ?url=VIDEO_URL
 * Mode 2 — File Upload : POST multipart/form-data, field "video"
 * Mode 3 — Discovery   : GET tanpa parameter (probe endpoint SliceDrive)
 *
 * Changelog v3.0:
 *  - Pipeline 5-langkah lengkap (validate/probe/download/upload/verify)
 *  - Debug Collector dengan timestamp & stage log
 *  - Discovery mode: probe endpoint SliceDrive saat tidak ada input
 *  - Dukungan ?url= via GET dan POST
 *  - Retry logic dengan backoff & UA rotation
 *  - Output JSON konsisten: success, steps, result, debug_log, elapsed_ms
 */

// ── OUTPUT GUARD ──────────────────────────────────────────────────────────────
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'PHP fatal error: ' . $err['message'] . ' (line ' . $err['line'] . ')',
        ]);
    } else {
        ob_end_flush();
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('SD_UPLOAD_API',   'https://slicedrive.com/api/upload');
define('SD_CDN_BASE',     'https://cdn.slicedrive.com/');
define('SD_WATCH_BASE',   'https://slicedrive.com/upload/?v=');
define('MAX_FILE_SIZE',   500 * 1024 * 1024); // 500 MB
define('REQUEST_TIMEOUT', 180);
define('CONN_TIMEOUT',    20);
define('MAX_ATTEMPTS',    3);
define('BACKOFF_BASE',    1);   // detik (0, 1, 2)
define('VERSION',         '3.0.0');

// ── USER AGENT POOL ───────────────────────────────────────────────────────────
$UA_POOL = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
];

// ── DEBUG COLLECTOR ───────────────────────────────────────────────────────────
class Debug {
    private static array $log   = [];
    private static float $start = 0.0;

    public static function init(): void {
        self::$start = microtime(true);
        self::log('INIT', 'Pipeline started', ['version' => VERSION, 'php' => PHP_VERSION]);
    }

    public static function log(string $stage, string $msg, mixed $data = null): void {
        self::$log[] = [
            'time_ms' => round((microtime(true) - self::$start) * 1000, 2),
            'stage'   => $stage,
            'message' => $msg,
            'data'    => $data,
        ];
    }

    public static function getLogs(): array { return self::$log; }

    public static function elapsed(): float {
        return round((microtime(true) - self::$start) * 1000, 2);
    }
}

// ── HTTP HELPER ───────────────────────────────────────────────────────────────
class Http {
    /**
     * HEAD/GET request untuk probe atau verify.
     */
    public static function get(string $url, array $headers = [], int $timeout = 30, bool $headOnly = false): array {
        $ch = curl_init();
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => $headOnly,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => CONN_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$respHeaders) {
                $len = strlen($header);
                $h   = explode(':', $header, 2);
                if (count($h) >= 2) $respHeaders[strtolower(trim($h[0]))] = trim($h[1]);
                return $len;
            },
        ]);
        $body  = curl_exec($ch);
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'body'    => $body,
            'headers' => $respHeaders,
            'info'    => $info,
            'error'   => $error ?: null,
            'status'  => (int)($info['http_code'] ?? 0),
        ];
    }

    /**
     * Unduh file ke path tujuan (streaming ke disk).
     */
    public static function download(string $url, string $destPath, string $ua, int $timeout = REQUEST_TIMEOUT): array {
        $fp = fopen($destPath, 'wb');
        if (!$fp) return ['success' => false, 'error' => 'Tidak bisa buat file temp: ' . $destPath];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => CONN_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_BUFFERSIZE     => 131072,
            CURLOPT_USERAGENT      => $ua,
        ]);
        $t0    = microtime(true);
        curl_exec($ch);
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $elapsed  = round(microtime(true) - $t0, 2);
        $fileSize = file_exists($destPath) ? filesize($destPath) : 0;
        $speedMBs = $elapsed > 0 ? round($fileSize / 1024 / 1024 / $elapsed, 2) : 0;

        if ($error)                      { @unlink($destPath); return ['success' => false, 'error' => 'cURL error: ' . $error]; }
        if ($info['http_code'] >= 400)   { @unlink($destPath); return ['success' => false, 'error' => 'HTTP ' . $info['http_code'] . ' saat download']; }
        if ($fileSize < 1024)            { @unlink($destPath); return ['success' => false, 'error' => "File terlalu kecil ({$fileSize} bytes), mungkin korup"]; }

        return [
            'success'   => true,
            'tmp_file'  => $destPath,
            'file_size' => $fileSize,
            'size_mb'   => round($fileSize / 1024 / 1024, 2),
            'speed_mbs' => $speedMBs,
            'elapsed_s' => $elapsed,
            'http_code' => (int)$info['http_code'],
        ];
    }

    /**
     * POST multipart/form-data upload ke SliceDrive.
     */
    public static function postMultipart(string $url, string $fieldName, string $filePath, string $fileName, string $mimeType, string $ua, array $extraHeaders = []): array {
        $cFile = new CURLFile($filePath, $mimeType, $fileName);
        $respHeaders = [];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [$fieldName => $cFile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CONN_TIMEOUT,
            CURLOPT_HTTPHEADER     => $extraHeaders,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$respHeaders) {
                $len = strlen($header);
                $h   = explode(':', $header, 2);
                if (count($h) >= 2) $respHeaders[strtolower(trim($h[0]))] = trim($h[1]);
                return $len;
            },
        ]);
        $body  = curl_exec($ch);
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'body'    => $body,
            'headers' => $respHeaders,
            'info'    => $info,
            'error'   => $error ?: null,
            'status'  => (int)($info['http_code'] ?? 0),
        ];
    }
}

// ── UUID HELPER ───────────────────────────────────────────────────────────────
function sd_uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function sd_is_uuid(string $s): bool {
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $s
    );
}

// ── VISITOR ID ────────────────────────────────────────────────────────────────
session_start();
$rawVid = trim($_POST['visitorId'] ?? $_GET['visitorId'] ?? '');
if ($rawVid && sd_is_uuid($rawVid)) {
    $VISITOR_ID = $rawVid;
} elseif (!empty($_SESSION['sd_visitor_id']) && sd_is_uuid($_SESSION['sd_visitor_id'])) {
    $VISITOR_ID = $_SESSION['sd_visitor_id'];
} else {
    $VISITOR_ID = sd_uuid();
    $_SESSION['sd_visitor_id'] = $VISITOR_ID;
}

// ── BASE HEADERS ──────────────────────────────────────────────────────────────
$BASE_HEADERS = [
    'Accept: application/json, */*',
    'Accept-Language: en-US,en;q=0.9,id;q=0.8',
    'Origin: https://slicedrive.com',
    'Referer: https://slicedrive.com/',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-origin',
    'Cache-Control: no-cache',
];

// ═════════════════════════════════════════════════════════════════════════════
// STEP 0: DISCOVERY — probe endpoint SliceDrive
// ═════════════════════════════════════════════════════════════════════════════
function discoverSliceDriveApi(string $visitorId, array $baseHeaders): array {
    Debug::log('DISCOVERY', 'Probing SliceDrive API endpoint');
    $probeUrl = SD_UPLOAD_API . '?visitorId=' . urlencode($visitorId);
    $probe    = Http::get($probeUrl, $baseHeaders, 10);

    $json = null;
    if (!empty($probe['body'])) {
        $decoded = json_decode($probe['body'], true);
        if ($decoded !== null) $json = $decoded;
    }

    $result = [
        'api_url'      => SD_UPLOAD_API,
        'visitor_id'   => $visitorId,
        'probe_status' => $probe['status'],
        'cors_allowed' => isset($probe['headers']['access-control-allow-origin']),
        'server'       => $probe['headers']['server'] ?? 'unknown',
        'cf_ray'       => $probe['headers']['cf-ray'] ?? null,
        'probe_json'   => $json,
    ];

    Debug::log('DISCOVERY', 'Probe selesai', ['status' => $probe['status'], 'cors' => $result['cors_allowed']]);
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1A: VALIDATE URL
// ═════════════════════════════════════════════════════════════════════════════
function validateVideoUrl(string $url): array {
    Debug::log('VALIDATE', 'Memvalidasi URL video', $url);

    if (empty($url)) return ['valid' => false, 'error' => 'Parameter ?url= diperlukan'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['valid' => false, 'error' => 'Format URL tidak valid'];

    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return ['valid' => false, 'error' => 'Hanya URL HTTP/HTTPS yang diizinkan'];

    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return ['valid' => false, 'error' => 'URL lokal/private tidak diizinkan'];
    if (filter_var($host, FILTER_VALIDATE_IP) &&
        filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['valid' => false, 'error' => 'IP private tidak diizinkan (SSRF protection)'];
    }

    Debug::log('VALIDATE', 'URL valid', ['host' => $host, 'scheme' => $scheme]);
    return ['valid' => true, 'host' => $host, 'scheme' => $scheme];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1B: VALIDATE UPLOADED FILE
// ═════════════════════════════════════════════════════════════════════════════
function validateUploadedFile(array $file): array {
    Debug::log('VALIDATE_FILE', 'Memvalidasi file upload', ['name' => $file['name'], 'size' => $file['size']]);

    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File melebihi upload_max_filesize di php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File melebihi MAX_FILE_SIZE di form',
        UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diupload',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temp tidak ada di server',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
        UPLOAD_ERR_EXTENSION  => 'Ekstensi PHP menghentikan upload',
    ];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => $errMap[$file['error']] ?? 'Upload error #' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['valid' => false, 'error' => 'File terlalu besar: ' . round($file['size'] / 1024 / 1024, 2) . 'MB (maks 500MB)'];
    }

    if ($file['size'] < 1024) {
        return ['valid' => false, 'error' => 'File terlalu kecil, kemungkinan korup'];
    }

    $extMimeMap = [
        'mp4'  => 'video/mp4',         'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',   'mov'  => 'video/quicktime',
        'webm' => 'video/webm',        'wmv'  => 'video/x-ms-wmv',
        'flv'  => 'video/x-flv',       '3gp'  => 'video/3gpp',
        '3g2'  => 'video/3gpp2',       'ogv'  => 'video/ogg',
        'ts'   => 'video/mp2t',        'm4v'  => 'video/x-m4v',
        'mpg'  => 'video/mpeg',        'mpeg' => 'video/mpeg',
        'm2ts' => 'video/mp2t',        'mts'  => 'video/mp2t',
    ];

    $fileName = basename($file['name']);
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = $extMimeMap[$ext] ?? '';

    // Fallback ke mime_content_type atau browser-reported type
    if (!$mimeType) {
        $detected = function_exists('mime_content_type') ? (string)(mime_content_type($file['tmp_name']) ?: '') : '';
        $mimeType = $detected ?: $file['type'];
    }

    $extOk  = isset($extMimeMap[$ext]);
    $mimeOk = str_starts_with($mimeType, 'video/') || in_array($mimeType, ['application/octet-stream', 'application/x-download', 'application/binary'], true);

    if (!$extOk && !$mimeOk) {
        return ['valid' => false, 'error' => "Tipe file tidak didukung: {$file['type']} (.{$ext}). Izinkan: " . implode(', ', array_keys($extMimeMap))];
    }

    if (!$mimeType) $mimeType = 'video/mp4';

    Debug::log('VALIDATE_FILE', 'File valid', ['name' => $fileName, 'ext' => $ext, 'mime' => $mimeType, 'size_mb' => round($file['size'] / 1024 / 1024, 2)]);

    return [
        'valid'        => true,
        'file_name'    => $fileName,
        'mime_type'    => $mimeType,
        'extension'    => $ext,
        'file_size'    => $file['size'],
        'file_size_mb' => round($file['size'] / 1024 / 1024, 2),
        'tmp_path'     => $file['tmp_name'],
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2: PROBE VIDEO SOURCE (URL mode only)
// ═════════════════════════════════════════════════════════════════════════════
function probeVideoSource(string $url): array {
    Debug::log('PROBE_SRC', 'Probing sumber video', $url);

    $extMimeMap = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska', 'flv' => 'video/x-flv',
        'ts'  => 'video/mp2t', 'm4v' => 'video/x-m4v', 'ogv' => 'video/ogg',
    ];

    $probe = Http::get($url, [
        'Accept: video/*,application/octet-stream,*/*',
        'Referer: https://slicedrive.com/',
    ], 20, true);

    $contentType = $probe['headers']['content-type'] ?? '';
    $fileSize    = (int)($probe['headers']['content-length'] ?? $probe['info']['download_content_length'] ?? 0);
    $finalUrl    = $probe['info']['url'] ?? $url;
    $path        = parse_url($finalUrl, PHP_URL_PATH);
    $fileName    = basename((string)$path);
    if (empty(pathinfo($fileName, PATHINFO_EXTENSION))) $fileName .= '.mp4';

    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = $extMimeMap[$ext] ?? (str_contains($contentType, 'video') ? explode(';', $contentType)[0] : 'video/mp4');

    $accessible = $probe['status'] >= 200 && $probe['status'] < 400;
    $error      = null;
    if (!$accessible) $error = "Sumber mengembalikan HTTP {$probe['status']}";
    if ($fileSize > MAX_FILE_SIZE) {
        $error      = 'File terlalu besar: ' . round($fileSize / 1024 / 1024, 2) . 'MB (maks 500MB)';
        $accessible = false;
    }

    $result = [
        'url'          => $url,
        'final_url'    => $finalUrl,
        'status'       => $probe['status'],
        'content_type' => $contentType,
        'file_size'    => $fileSize,
        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        'file_name'    => $fileName,
        'mime_type'    => $mimeType,
        'extension'    => $ext,
        'accessible'   => $accessible,
        'error'        => $error,
    ];

    Debug::log('PROBE_SRC', 'Probe selesai', ['status' => $probe['status'], 'size_mb' => $result['file_size_mb'], 'mime' => $mimeType, 'filename' => $fileName]);
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3: DOWNLOAD VIDEO
// ═════════════════════════════════════════════════════════════════════════════
function downloadVideo(string $url, string $fileName, string $ua): array {
    $tmpFile = sys_get_temp_dir() . '/sd_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    Debug::log('DOWNLOAD', 'Mengunduh video', ['url' => $url, 'tmp' => $tmpFile]);
    $result = Http::download($url, $tmpFile, $ua);
    if ($result['success']) {
        Debug::log('DOWNLOAD', 'Download selesai', ['size_mb' => $result['size_mb'], 'speed_mbs' => $result['speed_mbs'], 'elapsed_s' => $result['elapsed_s']]);
    } else {
        Debug::log('DOWNLOAD', 'Download GAGAL', ['error' => $result['error']]);
    }
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4: UPLOAD TO SLICEDRIVE (dengan retry & field-name rotation)
// ═════════════════════════════════════════════════════════════════════════════
function uploadToSliceDrive(string $tmpFile, string $fileName, string $mimeType, string $visitorId, array $baseHeaders, array $uaPool): array {
    Debug::log('UPLOAD', 'Mengupload ke SliceDrive', ['file' => $fileName, 'mime' => $mimeType]);

    $uploadUrl  = SD_UPLOAD_API . '?visitorId=' . urlencode($visitorId);
    $fieldNames = ['file', 'video', 'upload'];
    $lastError  = 'Tidak ada respons dari SliceDrive';
    $rawBody    = null;
    $httpStatus = 0;
    $usedField  = 'file';

    for ($attempt = 0; $attempt < MAX_ATTEMPTS; $attempt++) {
        if ($attempt > 0) sleep($attempt * BACKOFF_BASE);

        $usedField = $fieldNames[min($attempt, count($fieldNames) - 1)];
        $ua        = $uaPool[$attempt % count($uaPool)];

        Debug::log('UPLOAD', "Percobaan " . ($attempt + 1) . " (field: {$usedField})", ['ua_index' => $attempt % count($uaPool)]);

        $result = Http::postMultipart($uploadUrl, $usedField, $tmpFile, $fileName, $mimeType, $ua, $baseHeaders);
        $rawBody    = $result['body'];
        $httpStatus = $result['status'];

        if ($result['error']) {
            $lastError = 'cURL error: ' . $result['error'];
            Debug::log('UPLOAD', 'cURL error pada percobaan ' . ($attempt + 1), $lastError);
            continue;
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            Debug::log('UPLOAD', 'Upload HTTP sukses', ['status' => $httpStatus, 'body_len' => strlen($rawBody ?? '')]);
            break;
        }

        $lastError = "HTTP {$httpStatus} (percobaan " . ($attempt + 1) . ", field: {$usedField})";
        Debug::log('UPLOAD', 'HTTP gagal pada percobaan ' . ($attempt + 1), $lastError);
    }

    if (!$rawBody || $httpStatus < 200 || $httpStatus >= 300) {
        return ['success' => false, 'error' => 'SliceDrive gagal setelah ' . MAX_ATTEMPTS . ' percobaan: ' . $lastError, 'http_status' => $httpStatus];
    }

    // ── Parse response & ekstrak video ID ────────────────────────────────────
    $decoded  = json_decode($rawBody, true);
    $videoId  = null;
    $watchUrl = null;
    $cdnUrl   = null;

    if (is_array($decoded)) {
        Debug::log('UPLOAD', 'Response adalah JSON', $decoded);

        $videoId = $decoded['id'] ?? $decoded['videoId'] ?? $decoded['video_id'] ?? $decoded['fileId'] ?? $decoded['file_id'] ?? null;

        if (!empty($decoded['link'])) {
            $watchUrl = $decoded['link'];
            if (!$videoId && preg_match('/[?&\/]v=([A-Za-z0-9_-]{4,24})/', $decoded['link'], $m)) {
                $videoId = $m[1];
            }
        }

        if (!$videoId && !empty($decoded['url'])) {
            $cdnUrl = $decoded['url'];
            if (preg_match('/(?:v=|\/|id=)([A-Za-z0-9_-]{4,24})(?:\.mp4)?(?:[?&#\s]|$)/', $cdnUrl, $m)) {
                $videoId = $m[1];
            }
        }
    } else {
        Debug::log('UPLOAD', 'Response bukan JSON, scan raw body', substr($rawBody, 0, 500));
    }

    // Fallback: cari di raw string
    if (!$videoId && preg_match('/"(?:id|videoId|video_id|fileId|file_id)"\s*:\s*"([A-Za-z0-9_-]{4,24})"/', $rawBody, $m)) {
        $videoId = $m[1];
    }
    if (!$videoId && preg_match('/"link"\s*:\s*"[^"]*[?&\/]v=([A-Za-z0-9_-]{4,24})/', $rawBody, $m)) {
        $videoId = $m[1];
    }
    if (!$videoId && preg_match('/["\s:=\/]([A-Za-z0-9_-]{6,20})["\s]/', $rawBody, $m)) {
        $videoId = $m[1];
        Debug::log('UPLOAD', 'Gunakan fallback ID generik', $videoId);
    }

    if (!$videoId) {
        Debug::log('UPLOAD', 'Ekstrak video ID GAGAL', ['raw' => substr($rawBody, 0, 300)]);
        return [
            'success'      => false,
            'error'        => 'Tidak dapat mengekstrak video ID dari respons SliceDrive',
            'http_status'  => $httpStatus,
            'response_raw' => $rawBody,
            'response_json'=> $decoded,
        ];
    }

    $cdnUrl   = $cdnUrl   ?? (SD_CDN_BASE   . $videoId . '.mp4');
    $watchUrl = $watchUrl ?? (SD_WATCH_BASE . $videoId);

    Debug::log('UPLOAD', 'Upload sukses, ID diekstrak', ['video_id' => $videoId, 'watch_url' => $watchUrl, 'cdn_url' => $cdnUrl]);

    return [
        'success'       => true,
        'video_id'      => $videoId,
        'cdn_url'       => $cdnUrl,
        'watch_url'     => $watchUrl,
        'http_status'   => $httpStatus,
        'response_json' => $decoded,
        'used_field'    => $usedField,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5: VERIFY — cek file di CDN
// ═════════════════════════════════════════════════════════════════════════════
function verifyUpload(string $cdnUrl): array {
    Debug::log('VERIFY', 'Memverifikasi file di CDN', $cdnUrl);
    $probe    = Http::get($cdnUrl, ['Range: bytes=0-1023', 'Referer: https://slicedrive.com/'], 15, true);
    $verified = in_array($probe['status'], [200, 206], true);
    Debug::log('VERIFY', 'Hasil verifikasi', ['status' => $probe['status'], 'verified' => $verified]);
    return [
        'verified'     => $verified,
        'http_status'  => $probe['status'],
        'content_type' => $probe['headers']['content-type'] ?? null,
        'file_size'    => $probe['headers']['content-length'] ?? null,
        'cf_cache'     => $probe['headers']['cf-cache-status'] ?? null,
        'cf_ray'       => $probe['headers']['cf-ray'] ?? null,
        'etag'         => $probe['headers']['etag'] ?? null,
        'last_modified'=> $probe['headers']['last-modified'] ?? null,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// MAIN
// ═════════════════════════════════════════════════════════════════════════════
Debug::init();

$response = [
    'success'    => false,
    'version'    => VERSION,
    'timestamp'  => date('c'),
    'request'    => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'url'    => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
    ],
    'mode'       => null,
    'steps'      => [],
    'result'     => null,
    'error'      => null,
    'debug_log'  => [],
    'elapsed_ms' => 0,
];

// Baca input
$inputUrl = trim($_GET['url'] ?? $_POST['url'] ?? '');
$hasFile  = isset($_FILES['video']) && $_FILES['video']['error'] !== UPLOAD_ERR_NO_FILE;
$tmpFile  = null;

// ─── DISCOVERY MODE ───────────────────────────────────────────────────────────
if (empty($inputUrl) && !$hasFile) {
    $response['mode']    = 'discovery';
    $response['message'] = 'Tidak ada ?url= atau file. Menjalankan discovery mode.';
    $response['usage']   = [
        'endpoint' => 'https://upload.vidshare.my.id/slicedrive_upload.php',
        'modes'    => [
            'url_upload'  => 'GET/POST ?url=VIDEO_URL',
            'file_upload' => 'POST multipart/form-data, field "video"',
        ],
        'example'  => 'https://upload.vidshare.my.id/slicedrive_upload.php?url=https://example.com/video.mp4',
        'methods'  => ['GET', 'POST'],
    ];
    $response['discovery']  = discoverSliceDriveApi($VISITOR_ID, $BASE_HEADERS);
    $response['debug_log']  = Debug::getLogs();
    $response['elapsed_ms'] = Debug::elapsed();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

// ─── PIPELINE ─────────────────────────────────────────────────────────────────
try {

    // ══════════════════════════════════════════════════════════════════════════
    // MODE A: DIRECT FILE UPLOAD
    // ══════════════════════════════════════════════════════════════════════════
    if ($hasFile) {
        $response['mode'] = 'file_upload';
        Debug::log('MODE', 'Direct file upload mode');

        // STEP 1 — Validate
        $validate = validateUploadedFile($_FILES['video']);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // STEP 2 — File info (tidak perlu probe jaringan untuk upload langsung)
        $probe = [
            'source'       => 'direct_upload',
            'file_name'    => $validate['file_name'],
            'file_size'    => $validate['file_size'],
            'file_size_mb' => $validate['file_size_mb'],
            'mime_type'    => $validate['mime_type'],
            'extension'    => $validate['extension'],
            'accessible'   => true,
        ];
        $response['steps']['2_probe_source'] = $probe;
        Debug::log('PROBE_SRC', 'File info dari direct upload (skip network probe)', $probe);

        // STEP 3 — Skip download, gunakan PHP tmp file
        $tmpPath  = $validate['tmp_path'];
        $download = [
            'source'    => 'direct_upload',
            'success'   => true,
            'tmp_file'  => $tmpPath,
            'file_size' => $validate['file_size'],
            'size_mb'   => $validate['file_size_mb'],
            'speed_mbs' => 'N/A (direct upload)',
            'elapsed_s' => 0,
        ];
        $response['steps']['3_download'] = $download;
        Debug::log('DOWNLOAD', 'Dilewati — menggunakan PHP tmp file', ['tmp' => $tmpPath]);

        // STEP 4 — Upload
        $upload = uploadToSliceDrive($tmpPath, $validate['file_name'], $validate['mime_type'], $VISITOR_ID, $BASE_HEADERS, $UA_POOL);
        $response['steps']['4_upload'] = $upload;
        if (!$upload['success']) {
            $response['error'] = $upload['error'];
            throw new RuntimeException($upload['error']);
        }

        // STEP 5 — Verify
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'video_id'    => $upload['video_id'],
            'watch_url'   => $upload['watch_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $validate['file_name'],
            'file_size_mb'=> $validate['file_size_mb'],
            'mime_type'   => $validate['mime_type'],
            'verified'    => $verify['verified'],
            'visitor_id'  => $VISITOR_ID,
            'source_url'  => null,
        ];

        Debug::log('DONE', 'File upload pipeline selesai', ['video_id' => $upload['video_id'], 'verified' => $verify['verified']]);

    // ══════════════════════════════════════════════════════════════════════════
    // MODE B: URL UPLOAD
    // ══════════════════════════════════════════════════════════════════════════
    } else {
        $response['mode'] = 'url_upload';
        Debug::log('MODE', 'URL upload mode');

        // STEP 1 — Validate URL
        $validate = validateVideoUrl($inputUrl);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // STEP 2 — Probe source
        $probe = probeVideoSource($inputUrl);
        $response['steps']['2_probe_source'] = $probe;
        if (!$probe['accessible']) {
            $response['error'] = $probe['error'] ?? 'Sumber tidak dapat diakses';
            throw new RuntimeException($response['error']);
        }

        // STEP 3 — Download
        $ua       = $UA_POOL[0];
        $download = downloadVideo($inputUrl, $probe['file_name'], $ua);
        $tmpFile  = $download['tmp_file'] ?? null;
        $response['steps']['3_download'] = $download;
        if (!$download['success']) {
            $response['error'] = $download['error'];
            throw new RuntimeException($response['error']);
        }

        // STEP 4 — Upload
        $upload = uploadToSliceDrive($download['tmp_file'], $probe['file_name'], $probe['mime_type'], $VISITOR_ID, $BASE_HEADERS, $UA_POOL);
        $response['steps']['4_upload'] = $upload;

        // Bersihkan file temp setelah upload (berhasil maupun tidak)
        if ($tmpFile && file_exists($tmpFile)) {
            @unlink($tmpFile);
            $tmpFile = null;
            Debug::log('CLEANUP', 'File temp dihapus');
        }

        if (!$upload['success']) {
            $response['error'] = $upload['error'];
            throw new RuntimeException($upload['error']);
        }

        // STEP 5 — Verify
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'video_id'    => $upload['video_id'],
            'watch_url'   => $upload['watch_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $probe['file_name'],
            'file_size_mb'=> $download['size_mb'],
            'mime_type'   => $probe['mime_type'],
            'verified'    => $verify['verified'],
            'visitor_id'  => $VISITOR_ID,
            'source_url'  => $inputUrl,
        ];

        Debug::log('DONE', 'URL upload pipeline selesai', ['video_id' => $upload['video_id'], 'verified' => $verify['verified']]);
    }

} catch (Throwable $e) {
    if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
    $response['error'] = $e->getMessage();
    Debug::log('ERROR', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
}

$response['debug_log']  = Debug::getLogs();
$response['elapsed_ms'] = Debug::elapsed();

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
