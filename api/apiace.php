<?php
/**
 * apis6.php - Video Upload API Endpoint with Full Debug & Discovery
 * v2.0 - Added direct file upload support
 * Usage (URL):  ?url=VIDEO_URL
 * Usage (File): POST multipart/form-data with field "file"
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('ACEIMG_UPLOAD_API', 'https://api.aceimg.com/api/upload');
define('ACEIMG_CDN',        'https://cdn.aceimg.com/');
define('ACEIMG_VIEW',       'https://aceimg.com/upload/?f=');
define('VISITOR_ID',        '2e653f00-fd49-45eb-9138-c6f31a2bfaa5');
define('MAX_FILE_SIZE',     500 * 1024 * 1024); // 500MB
define('TEMP_DIR',          sys_get_temp_dir() . '/apis6_');
define('REQUEST_TIMEOUT',   120);
define('VERSION',           '2.0.0');

// ─── DEBUG COLLECTOR ──────────────────────────────────────────────────────────
class Debug {
    private static array $log = [];
    private static float $start;

    public static function init(): void {
        self::$start = microtime(true);
        self::log('INIT', 'API started', ['version' => VERSION, 'php' => PHP_VERSION]);
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

// ─── HTTP HELPER ──────────────────────────────────────────────────────────────
class Http {
    public static function get(string $url, array $headers = [], int $timeout = 30): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len    = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) return $len;
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
                return $len;
            },
        ]);
        $responseHeaders = [];
        $body  = curl_exec($ch);
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return ['body' => $body, 'headers' => $responseHeaders, 'info' => $info, 'error' => $error, 'status' => $info['http_code'] ?? 0];
    }

    public static function postMultipart(string $url, array $fields, string $filePath, string $fileName, string $mimeType, array $headers = []): array {
        $cFile = new CURLFile($filePath, $mimeType, $fileName);
        $fields['file'] = $cFile;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len    = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) return $len;
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
                return $len;
            },
        ]);
        $responseHeaders = [];
        $body  = curl_exec($ch);
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return ['body' => $body, 'headers' => $responseHeaders, 'info' => $info, 'error' => $error, 'status' => $info['http_code'] ?? 0];
    }
}

// ─── DISCOVERY ────────────────────────────────────────────────────────────────
function discoverAceimgApi(): array {
    Debug::log('DISCOVERY', 'Probing aceimg API endpoint');
    $probeUrl = ACEIMG_UPLOAD_API . '?visitorId=' . VISITOR_ID;
    $probe    = Http::get($probeUrl, [
        'Origin: https://aceimg.com',
        'Referer: https://aceimg.com/',
        'Accept: application/json, text/plain, */*',
    ], 10);

    $discovery = [
        'api_url'       => ACEIMG_UPLOAD_API,
        'visitor_id'    => VISITOR_ID,
        'probe_status'  => $probe['status'],
        'probe_headers' => $probe['headers'],
        'cors_allowed'  => isset($probe['headers']['access-control-allow-origin']),
        'server'        => $probe['headers']['server'] ?? 'unknown',
        'cf_ray'        => $probe['headers']['cf-ray'] ?? null,
        'probe_json'    => null,
    ];

    if (!empty($probe['body'])) {
        $json = json_decode($probe['body'], true);
        if ($json !== null) $discovery['probe_json'] = $json;
    }

    Debug::log('DISCOVERY', 'Probe complete', ['status' => $probe['status'], 'cors' => $discovery['cors_allowed']]);
    return $discovery;
}

// ─── STEP 1: VALIDATE URL ─────────────────────────────────────────────────────
function validateVideoUrl(string $url): array {
    Debug::log('VALIDATE', 'Validating video URL', $url);
    if (empty($url)) return ['valid' => false, 'error' => 'Parameter ?url= is required'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['valid' => false, 'error' => 'Invalid URL format'];
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'])) return ['valid' => false, 'error' => 'Only HTTP/HTTPS URLs allowed'];
    $host = parse_url($url, PHP_URL_HOST);
    $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
    if (in_array($host, $blockedHosts) || preg_match('/^10\.|^192\.168\.|^172\.(1[6-9]|2\d|3[01])\./', $host)) {
        return ['valid' => false, 'error' => 'Private/local URLs not allowed'];
    }
    Debug::log('VALIDATE', 'URL is valid', ['host' => $host, 'scheme' => $scheme]);
    return ['valid' => true, 'host' => $host, 'scheme' => $scheme];
}

// ─── STEP 1b: VALIDATE UPLOADED FILE ─────────────────────────────────────────
function validateUploadedFile(array $file): array {
    Debug::log('VALIDATE_FILE', 'Validating uploaded file', ['name' => $file['name'], 'size' => $file['size']]);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload',
        ];
        return ['valid' => false, 'error' => $errMap[$file['error']] ?? 'Unknown upload error #' . $file['error']];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['valid' => false, 'error' => 'File too large: ' . round($file['size']/1024/1024, 2) . 'MB (max 500MB)'];
    }

    if ($file['size'] < 1024) {
        return ['valid' => false, 'error' => 'File too small, likely corrupted'];
    }

    $allowedMimes = [
        'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
        'video/x-matroska', 'video/x-flv', 'video/mp2t', 'video/x-m4v',
        'application/octet-stream', // some browsers send this for video files
    ];

    $mimeMap = [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'mkv'  => 'video/x-matroska',
        'flv'  => 'video/x-flv',
        'ts'   => 'video/mp2t',
        'm4v'  => 'video/x-m4v',
    ];

    $fileName = $file['name'];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = $mimeMap[$ext] ?? $file['type'];

    // Accept if extension is video OR mime is video
    $extOk  = array_key_exists($ext, $mimeMap);
    $mimeOk = strpos($file['type'], 'video') !== false || in_array($file['type'], $allowedMimes);

    if (!$extOk && !$mimeOk) {
        return ['valid' => false, 'error' => "Unsupported file type: {$file['type']} (.{$ext}). Allowed: mp4, webm, mov, avi, mkv, flv, ts, m4v"];
    }

    Debug::log('VALIDATE_FILE', 'File valid', ['name' => $fileName, 'ext' => $ext, 'mime' => $mimeType, 'size_mb' => round($file['size']/1024/1024,2)]);

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

// ─── STEP 2: PROBE VIDEO SOURCE ───────────────────────────────────────────────
function probeVideoSource(string $url): array {
    Debug::log('PROBE_SRC', 'Probing video source', $url);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$respHeaders) {
            $len = strlen($header);
            $h   = explode(':', $header, 2);
            if (count($h) >= 2) $respHeaders[strtolower(trim($h[0]))] = trim($h[1]);
            return $len;
        },
    ]);
    $respHeaders = [];
    curl_exec($ch);
    $info  = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $contentType = $respHeaders['content-type'] ?? '';
    $fileSize    = (int)($respHeaders['content-length'] ?? $info['download_content_length'] ?? 0);
    $finalUrl    = $info['url'] ?? $url;
    $path        = parse_url($finalUrl, PHP_URL_PATH);
    $fileName    = basename($path);
    if (empty(pathinfo($fileName, PATHINFO_EXTENSION))) $fileName .= '.mp4';

    $mimeMap = ['mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','flv'=>'video/x-flv','ts'=>'video/mp2t','m4v'=>'video/x-m4v'];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeType = $mimeMap[$ext] ?? (strpos($contentType, 'video') !== false ? $contentType : 'video/mp4');

    $result = [
        'url'          => $url,
        'final_url'    => $finalUrl,
        'status'       => $info['http_code'],
        'content_type' => $contentType,
        'file_size'    => $fileSize,
        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        'file_name'    => $fileName,
        'mime_type'    => $mimeType,
        'extension'    => $ext,
        'headers'      => $respHeaders,
        'error'        => $error ?: null,
        'accessible'   => $info['http_code'] >= 200 && $info['http_code'] < 400,
    ];

    if (!$result['accessible']) $result['error'] = "Source returned HTTP {$info['http_code']}";
    if ($fileSize > MAX_FILE_SIZE) { $result['error'] = 'File too large: ' . $result['file_size_mb'] . 'MB (max 500MB)'; $result['accessible'] = false; }

    Debug::log('PROBE_SRC', 'Source probed', ['status'=>$result['status'],'size_mb'=>$result['file_size_mb'],'mime'=>$mimeType,'filename'=>$fileName]);
    return $result;
}

// ─── STEP 3: DOWNLOAD VIDEO ───────────────────────────────────────────────────
function downloadVideo(string $url, string $fileName): array {
    $tmpFile = TEMP_DIR . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    Debug::log('DOWNLOAD', 'Downloading video', ['url' => $url, 'tmp' => $tmpFile]);

    $fp = fopen($tmpFile, 'wb');
    if (!$fp) return ['success' => false, 'error' => 'Cannot create temp file: ' . $tmpFile];

    $startTime = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_BUFFERSIZE     => 1024 * 128,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    ]);
    curl_exec($ch);
    $info  = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    $elapsed  = round(microtime(true) - $startTime, 2);
    $fileSize = file_exists($tmpFile) ? filesize($tmpFile) : 0;
    $speedMBs = $elapsed > 0 ? round($fileSize / 1024 / 1024 / $elapsed, 2) : 0;

    if ($error) { @unlink($tmpFile); return ['success' => false, 'error' => 'cURL error: ' . $error]; }
    if ($info['http_code'] >= 400) { @unlink($tmpFile); return ['success' => false, 'error' => "HTTP {$info['http_code']} downloading video"]; }
    if ($fileSize < 1024) { @unlink($tmpFile); return ['success' => false, 'error' => "Downloaded file too small ({$fileSize} bytes)"]; }

    Debug::log('DOWNLOAD', 'Download complete', ['size_mb'=>round($fileSize/1024/1024,2),'speed_mbs'=>$speedMBs,'elapsed_s'=>$elapsed]);
    return ['success'=>true,'tmp_file'=>$tmpFile,'file_size'=>$fileSize,'size_mb'=>round($fileSize/1024/1024,2),'speed_mbs'=>$speedMBs,'elapsed_s'=>$elapsed,'http_code'=>$info['http_code']];
}

// ─── STEP 4: UPLOAD TO ACEIMG ─────────────────────────────────────────────────
function uploadToAceimg(string $tmpFile, string $fileName, string $mimeType): array {
    Debug::log('UPLOAD', 'Uploading to aceimg', ['file' => $fileName, 'mime' => $mimeType]);

    $uploadUrl = ACEIMG_UPLOAD_API . '?visitorId=' . VISITOR_ID;
    $headers   = [
        'Origin: https://aceimg.com',
        'Referer: https://aceimg.com/',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site',
    ];

    $result = Http::postMultipart($uploadUrl, [], $tmpFile, $fileName, $mimeType, $headers);
    Debug::log('UPLOAD', 'Upload response received', ['status'=>$result['status'],'body_len'=>strlen($result['body']??'')]);

    if ($result['error']) return ['success'=>false,'error'=>'Upload cURL error: '.$result['error'],'raw'=>$result];

    $body    = $result['body'];
    $json    = json_decode($body, true);
    $fileKey = null; $viewUrl = null; $directUrl = null;

    $extractKey = function(string $val) use (&$fileKey, &$viewUrl, &$directUrl): bool {
        if (filter_var($val, FILTER_VALIDATE_URL)) {
            $query = [];
            parse_str(parse_url($val, PHP_URL_QUERY) ?? '', $query);
            if (!empty($query['f'])) { $fileKey = $query['f']; $viewUrl = $val; return true; }
            $pathFile = basename(parse_url($val, PHP_URL_PATH));
            if ($pathFile && strpos($pathFile, '.') !== false) { $fileKey = $pathFile; $directUrl = $val; return true; }
            if ($pathFile) { $fileKey = $pathFile; return true; }
        } else { $fileKey = $val; return true; }
        return false;
    };

    if ($json !== null) {
        Debug::log('UPLOAD', 'Response is JSON', $json);
        $possibleKeyFields = ['link','f','file','url','key','name','filename','id','path','hash'];
        foreach ($possibleKeyFields as $field) {
            if (!empty($json[$field]) && is_string($json[$field])) { if ($extractKey($json[$field])) break; }
        }
        if (!$fileKey && isset($json['data']) && is_array($json['data'])) {
            foreach ($possibleKeyFields as $field) {
                if (!empty($json['data'][$field]) && is_string($json['data'][$field])) { if ($extractKey($json['data'][$field])) break; }
            }
        }
    } else {
        Debug::log('UPLOAD', 'Response is not JSON, scanning body', substr($body, 0, 500));
        $patterns = [
            '/["\']f["\']\s*:\s*["\']([a-zA-Z0-9_-]+\.[a-z0-9]+)["\']/',
            '/["\']file["\']\s*:\s*["\']([a-zA-Z0-9_-]+\.[a-z0-9]+)["\']/',
            '/cdn\.aceimg\.com\/([a-zA-Z0-9_-]+\.[a-z0-9]+)/',
            '/aceimg\.com\/upload\/\?f=([a-zA-Z0-9_-]+\.[a-z0-9]+)/',
            '/["\']([a-zA-Z0-9]{8,12}\.(mp4|webm|mov|avi|mkv))["\']/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $m)) { $fileKey = $m[1]; break; }
        }
    }

    if ($fileKey) {
        $viewUrl   = $viewUrl   ?? (ACEIMG_VIEW . $fileKey);
        $directUrl = $directUrl ?? (ACEIMG_CDN  . $fileKey);
        Debug::log('UPLOAD', 'File key extracted', ['key'=>$fileKey,'view_url'=>$viewUrl,'direct_url'=>$directUrl]);
    } else {
        Debug::log('UPLOAD', 'File key extraction FAILED', ['json_keys'=>$json!==null?array_keys($json):[]]);
    }

    return [
        'success'          => $result['status'] >= 200 && $result['status'] < 400 && $fileKey !== null,
        'http_status'      => $result['status'],
        'file_key'         => $fileKey,
        'view_url'         => $viewUrl,
        'direct_url'       => $directUrl,
        'cdn_url'          => $fileKey ? ACEIMG_CDN . $fileKey : null,
        'response_json'    => $json,
        'response_raw'     => $body,
        'response_headers' => $result['headers'],
        'error'            => (!$fileKey && $result['status'] < 400) ? 'Could not extract file key from response' : null,
    ];
}

// ─── STEP 5: VERIFY ──────────────────────────────────────────────────────────
function verifyUpload(string $cdnUrl): array {
    Debug::log('VERIFY', 'Verifying CDN file', $cdnUrl);
    $probe    = Http::get($cdnUrl, ['Range: bytes=0-1023','Referer: https://aceimg.com/'], 15);
    $verified = in_array($probe['status'], [200, 206]);
    Debug::log('VERIFY', 'Verification result', ['status'=>$probe['status'],'verified'=>$verified,'cf_cache'=>$probe['headers']['cf-cache-status']??null]);
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

// ─── MAIN HANDLER ─────────────────────────────────────────────────────────────
Debug::init();

$response = [
    'success'    => false,
    'version'    => VERSION,
    'timestamp'  => date('c'),
    'request'    => ['method'=>$_SERVER['REQUEST_METHOD'],'url'=>(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],'ip'=>$_SERVER['REMOTE_ADDR']??null],
    'mode'       => null,
    'steps'      => [],
    'result'     => null,
    'error'      => null,
    'debug_log'  => [],
    'elapsed_ms' => 0,
];

$inputUrl  = trim($_GET['url'] ?? $_POST['url'] ?? '');
$hasFile   = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
$tmpFile   = null;

// ── Discovery mode ─────────────────────────────────────────────────────────────
if (empty($inputUrl) && !$hasFile) {
    $response['mode']    = 'discovery';
    $response['message'] = 'No ?url= or file provided. Running API discovery mode.';
    $response['usage']   = [
        'endpoint'  => 'https://upload.vidshare.my.id/apis6.php',
        'modes'     => [
            'url_upload'  => 'GET/POST ?url=VIDEO_URL',
            'file_upload' => 'POST multipart/form-data with field "file"',
        ],
        'example'   => 'https://upload.vidshare.my.id/apis6.php?url=https://example.com/video.mp4',
        'methods'   => ['GET','POST'],
    ];
    $response['discovery'] = discoverAceimgApi();
    $response['debug_log'] = Debug::getLogs();
    $response['elapsed_ms']= Debug::elapsed();
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // ══════════════════════════════════════════════════════════════════════════
    // MODE A: DIRECT FILE UPLOAD
    // ══════════════════════════════════════════════════════════════════════════
    if ($hasFile) {
        $response['mode'] = 'file_upload';
        Debug::log('MODE', 'Direct file upload mode');

        // ── STEP 1: Validate uploaded file ─────────────────────────────────────
        $validate = validateUploadedFile($_FILES['file']);
        $response['steps']['1_validate'] = $validate;

        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // ── STEP 2: File info (replicate probe step for UI consistency) ─────────
        $probe = [
            'source'       => 'direct_upload',
            'file_name'    => $validate['file_name'],
            'file_size'    => $validate['file_size'],
            'file_size_mb' => $validate['file_size_mb'],
            'mime_type'    => $validate['mime_type'],
            'extension'    => $validate['extension'],
            'accessible'   => true,
            'success'      => true,
        ];
        $response['steps']['2_probe_source'] = $probe;
        Debug::log('PROBE_SRC', 'File info from direct upload', $probe);

        // ── STEP 3: No download needed — use PHP tmp file ───────────────────────
        $tmpPath = $validate['tmp_path'];
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
        Debug::log('DOWNLOAD', 'Skipped — using PHP uploaded tmp file', ['tmp'=>$tmpPath]);

        // ── STEP 4: Upload to AceImg ────────────────────────────────────────────
        $upload = uploadToAceimg($tmpPath, $validate['file_name'], $validate['mime_type']);
        $response['steps']['4_upload'] = $upload;

        if (!$upload['success']) {
            $response['error'] = $upload['error'] ?? 'Upload failed (HTTP ' . $upload['http_status'] . ')';
            throw new RuntimeException($response['error']);
        }

        // ── STEP 5: Verify ──────────────────────────────────────────────────────
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'file_key'    => $upload['file_key'],
            'view_url'    => $upload['view_url'],
            'direct_url'  => $upload['direct_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $validate['file_name'],
            'file_size_mb'=> $validate['file_size_mb'],
            'mime_type'   => $validate['mime_type'],
            'verified'    => $verify['verified'],
            'source_url'  => null,
        ];

        Debug::log('DONE', 'File upload pipeline complete', ['file_key'=>$upload['file_key'],'verified'=>$verify['verified']]);

    // ══════════════════════════════════════════════════════════════════════════
    // MODE B: URL UPLOAD (original flow)
    // ══════════════════════════════════════════════════════════════════════════
    } else {
        $response['mode'] = 'url_upload';
        Debug::log('MODE', 'URL upload mode');

        // ── STEP 1: Validate URL ────────────────────────────────────────────────
        $validate = validateVideoUrl($inputUrl);
        $response['steps']['1_validate'] = $validate;

        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // ── STEP 2: Probe source ────────────────────────────────────────────────
        $probe = probeVideoSource($inputUrl);
        $response['steps']['2_probe_source'] = $probe;

        if (!$probe['accessible']) {
            $response['error'] = $probe['error'] ?? 'Source not accessible';
            throw new RuntimeException($response['error']);
        }

        // ── STEP 3: Download ────────────────────────────────────────────────────
        $download = downloadVideo($inputUrl, $probe['file_name']);
        $response['steps']['3_download'] = $download;
        $tmpFile  = $download['tmp_file'] ?? null;

        if (!$download['success']) {
            $response['error'] = $download['error'];
            throw new RuntimeException($response['error']);
        }

        // ── STEP 4: Upload ──────────────────────────────────────────────────────
        $upload = uploadToAceimg($download['tmp_file'], $probe['file_name'], $probe['mime_type']);
        $response['steps']['4_upload'] = $upload;

        if ($tmpFile && file_exists($tmpFile)) { @unlink($tmpFile); $tmpFile = null; Debug::log('CLEANUP', 'Temp file deleted'); }

        if (!$upload['success']) {
            $response['error'] = $upload['error'] ?? 'Upload failed (HTTP ' . $upload['http_status'] . ')';
            throw new RuntimeException($response['error']);
        }

        // ── STEP 5: Verify ──────────────────────────────────────────────────────
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'file_key'    => $upload['file_key'],
            'view_url'    => $upload['view_url'],
            'direct_url'  => $upload['direct_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $probe['file_name'],
            'file_size_mb'=> $download['size_mb'],
            'mime_type'   => $probe['mime_type'],
            'verified'    => $verify['verified'],
            'source_url'  => $inputUrl,
        ];

        Debug::log('DONE', 'URL upload pipeline complete', ['file_key'=>$upload['file_key'],'verified'=>$verify['verified']]);
    }

} catch (Throwable $e) {
    if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);
    $response['error'] = $e->getMessage();
    Debug::log('ERROR', $e->getMessage(), ['file'=>$e->getFile(),'line'=>$e->getLine()]);
    http_response_code(500);
}

$response['debug_log']  = Debug::getLogs();
$response['elapsed_ms'] = Debug::elapsed();

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
