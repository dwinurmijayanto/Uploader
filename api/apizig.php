<?php
/**
 * zigvideo_upload.php — video.zig.ht Upload Proxy v3.0
 *
 * Mengadopsi pipeline aceimg (apis6.php):
 *   validate → probe → download → upload → verify
 *   + Debug Collector, Discovery Mode, ?url= GET/POST param
 *
 * Mode 1 — URL Upload    : GET/POST ?url=VIDEO_URL
 * Mode 2 — File Upload   : POST multipart/form-data, field "video" / "file" / dll
 * Mode 3 — Discovery     : GET tanpa parameter (probe endpoint + field discovery)
 * Mode 4 — Probe Fields  : GET ?probe=1 (test field name pakai file kecil)
 * Mode 5 — Env Info      : GET ?info=1
 *
 * Params opsional:
 *   &field=xxx      → paksa field name manual
 *   &discover=1     → coba semua kandidat field name
 *   &debug=1        → sertakan debug_log di output (otomatis aktif di discover)
 *
 * Changelog v3.0:
 *  - Pipeline 5-langkah konsisten: validate / probe / download / upload / verify
 *  - Debug Collector (class Debug) dengan timestamp ms & stage log
 *  - Discovery mode: probe endpoint + tampilkan usage docs saat tanpa parameter
 *  - ?url= diterima via GET maupun POST
 *  - ob_start() output guard + shutdown handler fatal error → JSON bersih
 *  - Retry logic dengan backoff & UA rotation
 *  - Output JSON konsisten: success, version, timestamp, mode, steps, result,
 *    error, debug_log, elapsed_ms
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
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Field-Name');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('ZIG_UPLOAD_API', 'https://video.zig.ht/api/videos/upload');
define('ZIG_FILE_BASE',  'https://video.zig.ht/api/videos/file/');
define('ZIG_SHARE_BASE', 'https://video.zig.ht/v/');
define('ZIG_STREAM_BASE','https://video.zig.ht/api/videos/stream/');
define('ZIG_EMBED_BASE', 'https://video.zig.ht/embed/');
define('MAX_FILE_SIZE',  500 * 1024 * 1024); // 500 MB
define('REQUEST_TIMEOUT', 300);
define('CONN_TIMEOUT',    15);
define('MAX_ATTEMPTS',    3);
define('BACKOFF_BASE',    1);
define('VERSION',         '3.0.0');

// Kandidat field name — urutan dari paling umum
$FIELD_CANDIDATES = [
    'video', 'file', 'upload', 'media', 'attachment',
    'clip', 'recording', 'mp4', 'content', 'data',
    'source', 'input', 'asset', 'item', 'document',
];

// ── UA POOL ───────────────────────────────────────────────────────────────────
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
    public static function get(string $url, array $headers = [], int $timeout = 30, bool $headOnly = false): array {
        $respHeaders = [];
        $ch = curl_init();
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

    public static function download(string $url, string $destPath, string $ua): array {
        $fp = fopen($destPath, 'wb');
        if (!$fp) return ['success' => false, 'error' => 'Tidak bisa buat file temp: ' . $destPath];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CONN_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_BUFFERSIZE     => 131072,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_REFERER        => 'https://video.zig.ht/',
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

        if ($error)                    { @unlink($destPath); return ['success' => false, 'error' => 'cURL error: ' . $error]; }
        if ($info['http_code'] >= 400) { @unlink($destPath); return ['success' => false, 'error' => 'HTTP ' . $info['http_code'] . ' saat download']; }
        if ($fileSize < 1024)          { @unlink($destPath); return ['success' => false, 'error' => "File terlalu kecil ({$fileSize} bytes), mungkin korup"]; }

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

    public static function postMultipart(string $url, string $fieldName, string $filePath, string $fileName, string $mimeType, string $ua, array $extraHeaders = []): array {
        $respHeaders = [];
        $cFile = new CURLFile($filePath, $mimeType, $fileName);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [$fieldName => $cFile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
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
            'body'     => $body,
            'headers'  => $respHeaders,
            'info'     => $info,
            'error'    => $error ?: null,
            'status'   => (int)($info['http_code'] ?? 0),
            'time_sec' => round($info['total_time'] ?? 0, 2),
        ];
    }
}

// ── ZIG HEADERS ───────────────────────────────────────────────────────────────
function zigHeaders(string $ua): array {
    return [
        'Accept: application/json, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Origin: https://video.zig.ht',
        'Referer: https://video.zig.ht/',
        'Sec-CH-UA: "Chromium";v="148", "Google Chrome";v="148", "Not/A)Brand";v="99"',
        'Sec-CH-UA-Mobile: ?0',
        'Sec-CH-UA-Platform: "Windows"',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Storage-Access: active',
        'User-Agent: ' . $ua,
        'Priority: u=1, i',
    ];
}

// ── BUILD RESULT ──────────────────────────────────────────────────────────────
function buildResult(array $res, string $fileName, string $basename, int $fileSize, ?string $srcUrl): array {
    $tok   = $res['shareToken'] ?? '';
    $fname = $res['filename']   ?? $fileName;
    return [
        'id'            => $res['id']           ?? null,
        'title'         => $res['title']        ?? $basename,
        'filename'      => $fname,
        'original_name' => $res['originalName'] ?? $basename,
        'mime_type'     => $res['mimeType']     ?? 'video/mp4',
        'size_bytes'    => $res['size']         ?? $fileSize,
        'size_mb'       => round(($res['size'] ?? $fileSize) / 1024 / 1024, 2),
        'duration'      => $res['duration']     ?? null,
        'share_token'   => $tok                 ?: null,
        'allow_download'=> $res['allowDownload'] ?? true,
        'created_at'    => $res['createdAt']    ?? date('c'),
        'urls' => [
            'file'   => $fname ? ZIG_FILE_BASE   . $fname : null,
            'share'  => $tok   ? ZIG_SHARE_BASE  . $tok   : null,
            'stream' => $fname ? ZIG_STREAM_BASE . $fname : null,
            'embed'  => $tok   ? ZIG_EMBED_BASE  . $tok   : null,
        ],
        'source_url' => $srcUrl,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 0: DISCOVERY — probe endpoint + field candidates
// ═════════════════════════════════════════════════════════════════════════════
function discoverZigApi(array $uaPool): array {
    Debug::log('DISCOVERY', 'Probing video.zig.ht API endpoint');
    $probe = Http::get(ZIG_UPLOAD_API, [
        'Accept: application/json, */*',
        'Origin: https://video.zig.ht',
        'Referer: https://video.zig.ht/',
    ], 10, true);

    $result = [
        'api_url'      => ZIG_UPLOAD_API,
        'probe_status' => $probe['status'],
        'cors_allowed' => isset($probe['headers']['access-control-allow-origin']),
        'server'       => $probe['headers']['server'] ?? 'unknown',
        'cf_ray'       => $probe['headers']['cf-ray'] ?? null,
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
        'mp4'  => 'video/mp4',       'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo', 'mov'  => 'video/quicktime',
        'webm' => 'video/webm',      'wmv'  => 'video/x-ms-wmv',
        'flv'  => 'video/x-flv',     '3gp'  => 'video/3gpp',
        '3g2'  => 'video/3gpp2',     'ogv'  => 'video/ogg',
        'ts'   => 'video/mp2t',      'm4v'  => 'video/x-m4v',
        'mpg'  => 'video/mpeg',      'mpeg' => 'video/mpeg',
        'm2ts' => 'video/mp2t',      'mts'  => 'video/mp2t',
    ];

    $fileName = basename($file['name']);
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = $extMimeMap[$ext] ?? '';

    if (!$mimeType) {
        $detected = function_exists('mime_content_type') ? (string)(mime_content_type($file['tmp_name']) ?: '') : '';
        $mimeType = $detected ?: $file['type'];
    }

    $extOk  = isset($extMimeMap[$ext]);
    $mimeOk = str_starts_with($mimeType, 'video/') ||
              in_array($mimeType, ['application/octet-stream', 'application/x-download', 'application/binary'], true);

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

// ── Cari file pertama valid dari $_FILES ──────────────────────────────────────
function detectUploadedFile(): ?array {
    if (empty($_FILES)) return null;
    foreach ($_FILES as $key => $f) {
        if (is_array($f['tmp_name'])) {
            foreach ($f['tmp_name'] as $i => $tmp) {
                if ($f['error'][$i] === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                    return ['key' => $key, 'tmp_name' => $tmp, 'name' => $f['name'][$i],
                            'size' => $f['size'][$i], 'error' => UPLOAD_ERR_OK,
                            'type' => $f['type'][$i] ?? 'video/mp4'];
                }
            }
        } else {
            if ($f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
                return ['key' => $key, 'tmp_name' => $f['tmp_name'], 'name' => $f['name'],
                        'size' => $f['size'], 'error' => UPLOAD_ERR_OK,
                        'type' => $f['type'] ?? 'video/mp4'];
            }
        }
    }
    return null;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2: PROBE VIDEO SOURCE
// ═════════════════════════════════════════════════════════════════════════════
function probeVideoSource(string $url, string $ua): array {
    Debug::log('PROBE_SRC', 'Probing sumber video', $url);

    $extMimeMap = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska', 'flv' => 'video/x-flv',
        'ts'  => 'video/mp2t', 'm4v' => 'video/x-m4v', 'ogv' => 'video/ogg',
    ];

    $probe = Http::get($url, [
        'Accept: video/*,application/octet-stream,*/*',
        'Referer: https://video.zig.ht/',
        'User-Agent: ' . $ua,
    ], 20, true);

    $contentType = $probe['headers']['content-type'] ?? '';
    $fileSize    = (int)($probe['headers']['content-length'] ?? $probe['info']['download_content_length'] ?? 0);
    $finalUrl    = $probe['info']['url'] ?? $url;
    $path        = parse_url($finalUrl, PHP_URL_PATH);
    $fileName    = basename((string)$path);
    if (empty(pathinfo($fileName, PATHINFO_EXTENSION))) $fileName .= '.mp4';
    $fileName    = preg_replace('/[^\w.\-]/', '_', $fileName);

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
    $tmpFile = sys_get_temp_dir() . '/zig_' . uniqid() . '_' . preg_replace('/[^\w.\-]/', '_', $fileName);
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
// STEP 4: UPLOAD TO video.zig.ht (retry + field-name rotation)
// ═════════════════════════════════════════════════════════════════════════════
function uploadToZig(string $tmpFile, string $fileName, string $mimeType, array $uaPool, array $fieldCandidates, ?string $forceField = null, bool $discover = false): array {
    Debug::log('UPLOAD', 'Mengupload ke video.zig.ht', ['file' => $fileName, 'mime' => $mimeType, 'discover' => $discover]);

    $fieldsToTry = $forceField ? [$forceField] : ($discover ? $fieldCandidates : [($fieldCandidates[0] ?? 'video')]);
    $maxAttempts = $discover ? count($fieldsToTry) : MAX_ATTEMPTS;

    $discoveryLog = [];
    $lastError    = 'Tidak ada respons dari video.zig.ht';
    $rawBody      = null;
    $httpStatus   = 0;
    $usedField    = $fieldsToTry[0] ?? 'video';

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if (!$discover && $attempt > 0) sleep($attempt * BACKOFF_BASE);

        $field = $discover ? ($fieldsToTry[$attempt] ?? $fieldsToTry[0]) : ($fieldsToTry[min($attempt, count($fieldsToTry) - 1)]);
        $ua    = $uaPool[$attempt % count($uaPool)];

        Debug::log('UPLOAD', ($discover ? "Discover field: {$field}" : "Percobaan " . ($attempt + 1) . " (field: {$field})"), ['ua_index' => $attempt % count($uaPool)]);

        $result = Http::postMultipart(ZIG_UPLOAD_API, $field, $tmpFile, $fileName, $mimeType, $ua, zigHeaders($ua));
        $rawBody    = $result['body'];
        $httpStatus = $result['status'];
        $decoded    = json_decode((string)$rawBody, true);

        // Deteksi "wrong field" dari pesan server
        $msg          = strtolower((string)($decoded['message'] ?? $decoded['error'] ?? ''));
        $isWrongField = str_contains($msg, 'unexpected field') || str_contains($msg, 'invalid field');

        if ($result['error']) {
            $lastError = 'cURL error: ' . $result['error'];
            Debug::log('UPLOAD', 'cURL error', ['error' => $lastError, 'field' => $field]);
            if ($discover) { $discoveryLog[$field] = ['http_code' => $httpStatus, 'success' => false, 'curl_error' => $result['error']]; usleep(500000); continue; }
            continue;
        }

        // Sukses = HTTP 2xx + ada field 'id' + tidak ada error
        $success = $httpStatus >= 200 && $httpStatus < 300
                && is_array($decoded)
                && !empty($decoded['id'])
                && !isset($decoded['error'])
                && !isset($decoded['message']);

        if ($discover) {
            $note = $success ? '✅ SUKSES — field name ditemukan!' : ($isWrongField ? '❌ Field name salah' : '⚠️ Field name benar tapi ada error lain');
            $discoveryLog[$field] = [
                'http_code'   => $httpStatus,
                'time_sec'    => $result['time_sec'],
                'success'     => $success,
                'wrong_field' => $isWrongField,
                'response'    => $decoded,
                'note'        => $note,
            ];
            if ($success) { $usedField = $field; $rawBody = $result['body']; break; }
            usleep(500000);
            continue;
        }

        $usedField = $field;

        if ($success) {
            Debug::log('UPLOAD', 'Upload HTTP sukses', ['status' => $httpStatus, 'field' => $field]);
            break;
        }

        $lastError = $isWrongField
            ? "Field name '{$field}' tidak diterima server"
            : "HTTP {$httpStatus} (percobaan " . ($attempt + 1) . ")";
        Debug::log('UPLOAD', 'Upload gagal pada percobaan ' . ($attempt + 1), ['field' => $field, 'error' => $lastError, 'msg' => $msg]);
    }

    $decoded = json_decode((string)$rawBody, true);

    $success = $httpStatus >= 200 && $httpStatus < 300
            && is_array($decoded)
            && !empty($decoded['id'])
            && !isset($decoded['error'])
            && !isset($decoded['message']);

    if (!$success) {
        $errMsg = $discover
            ? 'Tidak ada field name yang berhasil. Lihat discovery_log.'
            : 'video.zig.ht gagal setelah ' . MAX_ATTEMPTS . ' percobaan: ' . $lastError;
        Debug::log('UPLOAD', 'Upload GAGAL', ['http_status' => $httpStatus, 'raw' => substr((string)$rawBody, 0, 300)]);
        return [
            'success'       => false,
            'error'         => $errMsg,
            'http_status'   => $httpStatus,
            'response_raw'  => $rawBody,
            'response_json' => $decoded,
            'discovery_log' => $discover ? $discoveryLog : null,
            'used_field'    => $usedField,
        ];
    }

    $fname = $decoded['filename'] ?? '';
    Debug::log('UPLOAD', 'Upload sukses', ['id' => $decoded['id'], 'filename' => $fname, 'field' => $usedField]);
    return [
        'success'       => true,
        'id'            => $decoded['id'],
        'filename'      => $fname,
        'response_json' => $decoded,
        'http_status'   => $httpStatus,
        'used_field'    => $usedField,
        'discovery_log' => $discover ? $discoveryLog : null,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5: VERIFY — cek file di CDN / stream endpoint
// ═════════════════════════════════════════════════════════════════════════════
function verifyUpload(string $fileName, string $ua): array {
    $verifyUrl = ZIG_STREAM_BASE . $fileName;
    Debug::log('VERIFY', 'Memverifikasi file di stream endpoint', $verifyUrl);
    $probe    = Http::get($verifyUrl, [
        'Range: bytes=0-1023',
        'Referer: https://video.zig.ht/',
        'User-Agent: ' . $ua,
    ], 15, true);
    $verified = in_array($probe['status'], [200, 206], true);
    Debug::log('VERIFY', 'Hasil verifikasi', ['status' => $probe['status'], 'verified' => $verified]);
    return [
        'verified'     => $verified,
        'http_status'  => $probe['status'],
        'verify_url'   => $verifyUrl,
        'content_type' => $probe['headers']['content-type'] ?? null,
        'file_size'    => $probe['headers']['content-length'] ?? null,
        'cf_cache'     => $probe['headers']['cf-cache-status'] ?? null,
        'etag'         => $probe['headers']['etag'] ?? null,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// MAIN
// ═════════════════════════════════════════════════════════════════════════════
Debug::init();

$isDebug = isset($_GET['debug']) || isset($_GET['discover']);

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

$inputUrl    = trim($_GET['url'] ?? $_POST['url'] ?? '');
$forceField  = $_GET['field'] ?? $_SERVER['HTTP_X_FIELD_NAME'] ?? null;
$doDiscover  = isset($_GET['discover']);
$hasFile     = !empty($_FILES) && detectUploadedFile() !== null;
$tmpFile     = null;

// ─── MODE: ?info=1 ────────────────────────────────────────────────────────────
if (isset($_GET['info'])) {
    $response['mode']    = 'info';
    $response['success'] = true;
    $response['result']  = [
        'php_version'    => PHP_VERSION,
        'curl_version'   => curl_version()['version'],
        'tmp_dir'        => sys_get_temp_dir(),
        'tmp_writable'   => is_writable(sys_get_temp_dir()),
        'max_upload'     => ini_get('upload_max_filesize'),
        'post_max'       => ini_get('post_max_size'),
        'max_exec_sec'   => ini_get('max_execution_time'),
        'file_uploads'   => ini_get('file_uploads') ? 'On' : 'Off',
        'openssl'        => extension_loaded('openssl'),
        'curl'           => function_exists('curl_init'),
        'field_candidates' => $FIELD_CANDIDATES,
        'upload_api'     => ZIG_UPLOAD_API,
    ];
    $response['debug_log']  = Debug::getLogs();
    $response['elapsed_ms'] = Debug::elapsed();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

// ─── MODE: ?probe=1 ───────────────────────────────────────────────────────────
if (isset($_GET['probe'])) {
    $response['mode'] = 'probe';
    Debug::log('PROBE', 'Menguji semua kandidat field name dengan file minimal');

    $probePath = sys_get_temp_dir() . '/zig_probe_' . uniqid() . '.mp4';
    file_put_contents($probePath, "\x00\x00\x00\x08ftyp"); // 8-byte ftyp atom

    $probeResults = [];
    $winner       = null;

    foreach ($FIELD_CANDIDATES as $field) {
        $ua = $UA_POOL[0];
        $r  = Http::postMultipart(ZIG_UPLOAD_API, $field, $probePath, 'probe.mp4', 'video/mp4', $ua, zigHeaders($ua));
        $decoded  = json_decode((string)($r['body'] ?? ''), true);
        $msg      = strtolower((string)($decoded['message'] ?? $decoded['error'] ?? ''));
        $wrongField = str_contains($msg, 'unexpected field') || str_contains($msg, 'invalid field');
        $success    = $r['status'] >= 200 && $r['status'] < 300 && !empty($decoded['id']);

        $note = $success ? '✅ Field name BENAR dan upload sukses'
              : ($wrongField ? '❌ Field name SALAH (unexpected field)'
              : '⚠️ Field name BENAR tapi ada error lain (file terlalu kecil/korup adalah normal di probe)');

        $probeResults[$field] = [
            'http_code'   => $r['status'],
            'time_sec'    => $r['time_sec'],
            'success'     => $success,
            'wrong_field' => $wrongField,
            'message'     => $decoded['message'] ?? $decoded['error'] ?? null,
            'note'        => $note,
        ];

        Debug::log('PROBE', "Field '{$field}': " . ($wrongField ? 'SALAH' : ($success ? 'SUKSES' : 'BENAR tapi error lain')));

        if (!$wrongField) { $winner = $field; break; }
        usleep(400000);
    }

    @unlink($probePath);

    $response['success'] = true;
    $response['result']  = [
        'winner'     => $winner,
        'conclusion' => $winner
            ? "✅ Gunakan field name: \"{$winner}\" — tambahkan ?field={$winner} ke URL"
            : '❌ Semua field name menghasilkan Unexpected field. Server mungkin butuh auth token.',
        'probe_results' => $probeResults,
        'next_steps' => $winner
            ? ["GET ?url=VIDEO_URL&field={$winner}", "POST multipart/form-data dengan field \"{$winner}\""]
            : ['Cek apakah video.zig.ht butuh session/cookie/token', 'GET ?url=VIDEO_URL&discover=1'],
    ];
    $response['debug_log']  = Debug::getLogs();
    $response['elapsed_ms'] = Debug::elapsed();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

// ─── DISCOVERY MODE (tanpa input) ─────────────────────────────────────────────
if (empty($inputUrl) && !$hasFile) {
    $response['mode']    = 'discovery';
    $response['message'] = 'Tidak ada ?url= atau file. Menjalankan discovery mode.';
    $response['usage']   = [
        'endpoint' => 'https://upload.vidshare.my.id/zigvideo_upload.php',
        'modes'    => [
            'url_upload'   => 'GET/POST ?url=VIDEO_URL',
            'file_upload'  => 'POST multipart/form-data, field "video" / "file" / dll',
            'probe_fields' => 'GET ?probe=1',
            'env_info'     => 'GET ?info=1',
        ],
        'params_opsional' => [
            '&field=xxx'   => 'Paksa field name manual',
            '&discover=1'  => 'Coba semua kandidat field name',
            '&debug=1'     => 'Sertakan debug_log di output',
        ],
        'example' => 'https://upload.vidshare.my.id/zigvideo_upload.php?url=https://example.com/video.mp4',
        'methods' => ['GET', 'POST'],
    ];
    $response['discovery']  = discoverZigApi($UA_POOL);
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

        $uploaded = detectUploadedFile();
        if (!$uploaded) throw new RuntimeException('Tidak ada file upload yang valid ditemukan.');

        // STEP 1 — Validate
        $validate = validateUploadedFile($uploaded);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // Copy ke tmp baru agar PHP tmp tidak hilang sebelum cURL selesai
        $safeTmp = sys_get_temp_dir() . '/zig_up_' . uniqid() . '_' . $validate['file_name'];
        if (!copy($validate['tmp_path'], $safeTmp)) throw new RuntimeException('Gagal menyalin file upload ke tmp dir.');
        $tmpFile = $safeTmp;

        // STEP 2 — File info (skip network probe)
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
        Debug::log('PROBE_SRC', 'Pakai info file langsung (skip network probe)', $probe);

        // STEP 3 — Skip download
        $download = [
            'source'    => 'direct_upload',
            'success'   => true,
            'tmp_file'  => $tmpFile,
            'file_size' => $validate['file_size'],
            'size_mb'   => $validate['file_size_mb'],
            'speed_mbs' => 'N/A (direct upload)',
            'elapsed_s' => 0,
        ];
        $response['steps']['3_download'] = $download;
        Debug::log('DOWNLOAD', 'Dilewati — menggunakan PHP tmp file', ['tmp' => $tmpFile]);

        // STEP 4 — Upload
        $upload = uploadToZig($tmpFile, $validate['file_name'], $validate['mime_type'], $UA_POOL, $FIELD_CANDIDATES, $forceField, $doDiscover);
        @unlink($tmpFile); $tmpFile = null;
        $response['steps']['4_upload'] = $upload;
        if (!$upload['success']) {
            $response['error'] = $upload['error'];
            throw new RuntimeException($upload['error']);
        }

        // STEP 5 — Verify
        $verify = verifyUpload($upload['filename'], $UA_POOL[0]);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = array_merge(
            ['mode' => 'file_upload', 'field_used' => $upload['used_field']],
            buildResult($upload['response_json'], $upload['filename'], $validate['file_name'], $validate['file_size'], null)
        );
        if ($doDiscover && !empty($upload['discovery_log'])) $response['result']['discovery_log'] = $upload['discovery_log'];

        Debug::log('DONE', 'File upload pipeline selesai', ['id' => $upload['id'], 'verified' => $verify['verified']]);

    // ══════════════════════════════════════════════════════════════════════════
    // MODE B: URL UPLOAD
    // ══════════════════════════════════════════════════════════════════════════
    } else {
        $response['mode'] = 'url_upload';
        Debug::log('MODE', 'URL upload mode');

        $ua = $UA_POOL[0];

        // STEP 1 — Validate URL
        $validate = validateVideoUrl($inputUrl);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // STEP 2 — Probe source
        $probe = probeVideoSource($inputUrl, $ua);
        $response['steps']['2_probe_source'] = $probe;
        if (!$probe['accessible']) {
            $response['error'] = $probe['error'] ?? 'Sumber tidak dapat diakses';
            throw new RuntimeException($response['error']);
        }

        // STEP 3 — Download
        $download = downloadVideo($inputUrl, $probe['file_name'], $ua);
        $tmpFile  = $download['tmp_file'] ?? null;
        $response['steps']['3_download'] = $download;
        if (!$download['success']) {
            $response['error'] = $download['error'];
            throw new RuntimeException($response['error']);
        }

        // STEP 4 — Upload
        $upload = uploadToZig($download['tmp_file'], $probe['file_name'], $probe['mime_type'], $UA_POOL, $FIELD_CANDIDATES, $forceField, $doDiscover);
        @unlink($download['tmp_file']); $tmpFile = null;
        Debug::log('CLEANUP', 'File temp dihapus');
        $response['steps']['4_upload'] = $upload;
        if (!$upload['success']) {
            $response['error'] = $upload['error'];
            throw new RuntimeException($upload['error']);
        }

        // STEP 5 — Verify
        $verify = verifyUpload($upload['filename'], $ua);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = array_merge(
            ['mode' => 'url_upload', 'field_used' => $upload['used_field']],
            buildResult($upload['response_json'], $upload['filename'], $probe['file_name'], $download['file_size'], $inputUrl)
        );
        if ($doDiscover && !empty($upload['discovery_log'])) $response['result']['discovery_log'] = $upload['discovery_log'];

        Debug::log('DONE', 'URL upload pipeline selesai', ['id' => $upload['id'], 'verified' => $verify['verified']]);
    }

} catch (Throwable $e) {
    if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
    $response['error'] = $e->getMessage();
    Debug::log('ERROR', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
}

if ($isDebug || !$response['success']) {
    $response['debug_log'] = Debug::getLogs();
}
$response['elapsed_ms'] = Debug::elapsed();

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
