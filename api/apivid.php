<?php
/**
 * apis1.php — Videy Upload API
 * v5.0 — Adopsi sistem kerja aceimg (apis6):
 *   - Pipeline steps: validate → probe → download → upload → verify
 *   - Debug collector dengan timing per-stage
 *   - Discovery mode (tanpa param → info + probe endpoint)
 *   - GET/POST ?url=VIDEO_URL (tanpa action= wrapper)
 *   - POST multipart file upload tetap didukung
 *   - Retry upload 2x jika timeout
 *   - Rotating UA, SSL validation, SSRF protection
 *   - Response shape konsisten dengan aceimg
 */

// ── RUNTIME ────────────────────────────────────────────────────────────────────
@ini_set('memory_limit',       '256M');
@ini_set('max_execution_time', '300');
@set_time_limit(300);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── CONFIG ─────────────────────────────────────────────────────────────────────
define('VIDEY_UPLOAD_URL', 'https://videy.co/api/upload');
define('VIDEY_CDN_URL',    'https://cdn.videy.co/');
define('VIDEY_VIEW_URL',   'https://videy.co/v/?id=');
define('MAX_FILE_SIZE',    100 * 1024 * 1024); // 100 MB
define('UPLOAD_TIMEOUT',   240);
define('DOWNLOAD_TIMEOUT', 120);
define('CONNECT_TIMEOUT',   30);
define('UPLOAD_RETRY_MAX',   2);
define('VERSION',          '5.0.0');

// Rotating UA pool
$UA_POOL = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
];
define('UA', $UA_POOL[array_rand($UA_POOL)]);

// ── DEBUG COLLECTOR ────────────────────────────────────────────────────────────
class Debug {
    private static array $log   = [];
    private static float $start = 0.0;

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

// ── MISC HELPERS ───────────────────────────────────────────────────────────────
function fmtSize(int $b): string {
    if ($b < 1024)       return $b . ' B';
    if ($b < 1048576)    return round($b / 1024, 2) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 2) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}

function cleanupTemp(?string $path): void {
    if ($path && is_file($path)) @unlink($path);
}

// ── SSL HELPER ─────────────────────────────────────────────────────────────────
function getCaBundle(): ?string {
    $ob = ini_get('open_basedir');
    if ($ob !== '' && $ob !== false) return null;
    foreach ([
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
        '/usr/local/share/certs/ca-root-nss.crt',
    ] as $p) {
        if (@is_file($p)) return $p;
    }
    return null;
}

function applySslOpts(\CurlHandle $ch): void {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $ca = getCaBundle();
    if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
}

// ── SSRF PROTECTION ────────────────────────────────────────────────────────────
function isPrivateHost(string $host): bool {
    if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) return true;
    $ip = @gethostbyname($host);
    if (!$ip || $ip === $host) return false;
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

// ── MIME HELPERS ───────────────────────────────────────────────────────────────
function normMime(string $ct): string {
    return strtolower(trim(explode(';', $ct)[0]));
}

function isNonVideo(string $mime): bool {
    foreach ([
        'text/', 'image/', 'audio/', 'application/json', 'application/xml',
        'application/javascript', 'text/html', 'application/zip',
        'application/x-zip', 'application/pdf', 'application/msword',
        'application/vnd.openxmlformats', 'application/vnd.ms-',
    ] as $p) {
        if (str_starts_with($mime, $p)) return true;
    }
    return false;
}

function isAmbiguous(string $mime): bool {
    return in_array($mime, [
        '', 'application/octet-stream', 'binary/octet-stream',
        'application/binary', 'application/download',
        'application/force-download', 'application/x-download',
        'application/x-binary',
    ], true);
}

function mimeFromMagic(string $path): string {
    $fp = @fopen($path, 'rb');
    if (!$fp) return '';
    $b = (string) fread($fp, 64);
    fclose($fp);
    if (strlen($b) < 4) return '';

    if (strlen($b) >= 8 && substr($b, 4, 4) === 'ftyp') {
        $brand = substr($b, 8, 4);
        if (in_array($brand, ['qt  ', 'mqt ', 'MSNV'], true)) return 'video/quicktime';
        return 'video/mp4';
    }
    if (strlen($b) >= 8 && in_array(substr($b, 4, 4), ['moov','mdat','free','skip','wide','pnot'], true))
        return 'video/mp4';
    if (substr($b, 0, 4) === "\x1a\x45\xdf\xa3")
        return (strpos(substr($b, 0, 32), 'webm') !== false) ? 'video/webm' : 'video/x-matroska';
    if (substr($b, 0, 4) === 'RIFF' && strlen($b) >= 12) {
        $form = substr($b, 8, 4);
        if ($form === 'AVI ' || $form === 'AVIX') return 'video/x-msvideo';
    }
    if (substr($b, 0, 3) === 'FLV' && ord($b[3]) === 0x01) return 'video/x-flv';
    if (substr($b, 0, 4) === "\x30\x26\xb2\x75") return 'video/x-ms-wmv';
    if (substr($b, 0, 4) === 'OggS') return 'video/ogg';
    if (ord($b[0]) === 0x47) return 'video/mp2t';
    if (substr($b, 0, 3) === "\x00\x00\x01") {
        $byte = ord($b[3]);
        if ($byte >= 0xb0 && $byte <= 0xbf) return 'video/mpeg';
        if ($byte === 0xe0 || $byte === 0xe1) return 'video/mpeg';
    }
    if (substr($b, 0, 4) === "\x00\x00\x00\x01" &&
        in_array(ord($b[4]) & 0x1f, [0x07, 0x08, 0x05, 0x01], true)) return 'video/mp4';
    return '';
}

function mimeToExt(string $mime): string {
    return [
        'video/mp4'        => 'mp4', 'video/mpeg'       => 'mpg',
        'video/quicktime'  => 'mov', 'video/x-msvideo'  => 'avi',
        'video/x-ms-wmv'   => 'wmv', 'video/x-flv'      => 'flv',
        'video/x-matroska' => 'mkv', 'video/webm'        => 'webm',
        'video/3gpp'       => '3gp', 'video/3gpp2'       => '3g2',
        'video/ogg'        => 'ogv', 'video/mp2t'        => 'ts',
        'video/x-m4v'      => 'm4v',
    ][$mime] ?? 'mp4';
}

function detectMime(string $tmpPath, string $origName): string {
    $magic = mimeFromMagic($tmpPath);
    if ($magic) return $magic;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $m  = finfo_file($fi, $tmpPath);
        finfo_close($fi);
        if ($m && str_starts_with($m, 'video/')) return $m;
    }
    $ext    = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $extMap = [
        'mp4'=>'video/mp4','m4v'=>'video/x-m4v','mov'=>'video/quicktime',
        'avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','webm'=>'video/webm',
        'wmv'=>'video/x-ms-wmv','flv'=>'video/x-flv','3gp'=>'video/3gpp',
        '3g2'=>'video/3gpp2','ogv'=>'video/ogg','ts'=>'video/mp2t',
        'mpg'=>'video/mpeg','mpeg'=>'video/mpeg',
    ];
    return $extMap[$ext] ?? '';
}

// ── FILENAME HELPERS ───────────────────────────────────────────────────────────
function filenameFromDisposition(string $header): ?string {
    if (!$header) return null;
    if (preg_match("/filename\*\s*=\s*[^']+''([^\s;]+)/i", $header, $m)) return rawurldecode($m[1]);
    if (preg_match('/filename\s*=\s*["\']?([^"\';\r\n]+)["\']?/i', $header, $m)) return trim($m[1], " \t\"'");
    return null;
}

function filenameFromUrl(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $base = basename(urldecode($path));
    if (($q = strpos($base, '?')) !== false) $base = substr($base, 0, $q);
    return ($base && strlen($base) > 1) ? $base : null;
}

function ensureVideoExt(string $name, string $mime): string {
    if (!$name) return 'video_' . time() . '.' . mimeToExt($mime);
    $ext       = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $videoExts = ['mp4','mkv','avi','mov','wmv','flv','webm','3gp','3g2','ogv','ts','m4v','mpg','mpeg'];
    return in_array($ext, $videoExts, true) ? $name : ($name . '.' . mimeToExt($mime));
}

// ── DISCOVERY ──────────────────────────────────────────────────────────────────
function discoverVideyApi(): array {
    Debug::log('DISCOVERY', 'Probing Videy API endpoint');
    $ch = curl_init(VIDEY_UPLOAD_URL);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => ['Origin: https://videy.co', 'Referer: https://videy.co/'],
        CURLOPT_HEADERFUNCTION => function ($ch, $raw) use (&$rh) {
            $len = strlen($raw);
            $h   = explode(':', $raw, 2);
            if (count($h) >= 2) $rh[strtolower(trim($h[0]))] = trim($h[1]);
            return $len;
        },
    ]);
    $rh = [];
    applySslOpts($ch);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    Debug::log('DISCOVERY', 'Probe complete', ['status' => $code]);
    return [
        'api_url'      => VIDEY_UPLOAD_URL,
        'probe_status' => $code,
        'probe_headers'=> $rh,
        'cors_allowed' => isset($rh['access-control-allow-origin']),
        'server'       => $rh['server'] ?? 'unknown',
        'cf_ray'       => $rh['cf-ray'] ?? null,
    ];
}

// ── STEP 1: VALIDATE URL ───────────────────────────────────────────────────────
function validateVideoUrl(string $url): array {
    Debug::log('VALIDATE', 'Validating URL', $url);
    if (empty($url))
        return ['valid' => false, 'error' => 'Parameter ?url= wajib diisi'];
    if (!filter_var($url, FILTER_VALIDATE_URL))
        return ['valid' => false, 'error' => 'Format URL tidak valid'];
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https']))
        return ['valid' => false, 'error' => 'Hanya HTTP/HTTPS yang diizinkan'];
    $host = parse_url($url, PHP_URL_HOST);
    if (isPrivateHost($host))
        return ['valid' => false, 'error' => 'URL mengarah ke jaringan internal — ditolak'];
    Debug::log('VALIDATE', 'URL valid', ['host' => $host]);
    return ['valid' => true, 'host' => $host, 'scheme' => $scheme];
}

// ── STEP 1b: VALIDATE UPLOADED FILE ───────────────────────────────────────────
function validateUploadedFile(array $file): array {
    Debug::log('VALIDATE_FILE', 'Validating uploaded file', ['name' => $file['name'], 'size' => $file['size']]);
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'Melebihi upload_max_filesize di php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'Melebihi batas ukuran form',
        UPLOAD_ERR_PARTIAL    => 'Upload tidak lengkap',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dikirim',
        UPLOAD_ERR_NO_TMP_DIR => 'Direktori temp tidak ditemukan',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan ekstensi PHP',
    ];
    if ($file['error'] !== UPLOAD_ERR_OK)
        return ['valid' => false, 'error' => $errMap[$file['error']] ?? 'Upload error #' . $file['error']];
    if ($file['size'] > MAX_FILE_SIZE)
        return ['valid' => false, 'error' => 'File terlalu besar: ' . round($file['size']/1024/1024,2) . 'MB (maks 100MB)'];
    if ($file['size'] < 1024)
        return ['valid' => false, 'error' => 'File terlalu kecil, kemungkinan rusak'];

    $mimeMap = [
        'mp4'=>'video/mp4','m4v'=>'video/x-m4v','mov'=>'video/quicktime',
        'avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','webm'=>'video/webm',
        'wmv'=>'video/x-ms-wmv','flv'=>'video/x-flv','3gp'=>'video/3gpp',
        '3g2'=>'video/3gpp2','ogv'=>'video/ogg','ts'=>'video/mp2t',
        'mpg'=>'video/mpeg','mpeg'=>'video/mpeg',
    ];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeType = $mimeMap[$ext] ?? $file['type'];
    $extOk    = array_key_exists($ext, $mimeMap);
    $mimeOk   = strpos($file['type'], 'video') !== false || in_array($file['type'], ['application/octet-stream'], true);

    if (!$extOk && !$mimeOk)
        return ['valid' => false, 'error' => "Tipe file tidak didukung: {$file['type']} (.{$ext})"];

    Debug::log('VALIDATE_FILE', 'File valid', ['name' => $file['name'], 'mime' => $mimeType, 'size_mb' => round($file['size']/1024/1024,2)]);
    return [
        'valid'        => true,
        'file_name'    => $file['name'],
        'mime_type'    => $mimeType,
        'extension'    => $ext,
        'file_size'    => $file['size'],
        'file_size_mb' => round($file['size'] / 1024 / 1024, 2),
        'tmp_path'     => $file['tmp_name'],
    ];
}

// ── STEP 2: PROBE VIDEO SOURCE ─────────────────────────────────────────────────
function probeVideoSource(string $url): array {
    Debug::log('PROBE_SRC', 'Probing video source', $url);
    $hMime = ''; $hDisp = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => [
            'Accept: video/*, application/octet-stream, */*;q=0.5',
            'Accept-Encoding: identity',
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, $raw) use (&$hMime, &$hDisp) {
            $line = trim($raw);
            if (stripos($line, 'content-type:') === 0)
                $hMime = normMime(substr($line, 13));
            elseif (stripos($line, 'content-disposition:') === 0)
                $hDisp = substr($line, 20);
            return strlen($raw);
        },
    ]);
    applySslOpts($ch);
    curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fileSize = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $error    = curl_error($ch);
    curl_close($ch);

    $accessible = ($code >= 200 && $code < 400);

    // Resolve filename
    $fileName = null;
    if ($accessible && $hDisp) $fileName = filenameFromDisposition($hDisp);
    if (!$fileName) $fileName = filenameFromUrl($finalUrl);
    if (!$fileName) $fileName = filenameFromUrl($url);
    if (!$fileName) $fileName = 'video_' . time();
    if (($q = strpos($fileName, '?')) !== false) $fileName = substr($fileName, 0, $q);

    // Resolve mime
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeMap  = ['mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','flv'=>'video/x-flv','ts'=>'video/mp2t','m4v'=>'video/x-m4v'];
    $mimeType = (!$hMime || isAmbiguous($hMime)) ? ($mimeMap[$ext] ?? 'video/mp4') : $hMime;

    $result = [
        'url'          => $url,
        'final_url'    => $finalUrl,
        'status'       => $code,
        'content_type' => $hMime,
        'file_size'    => $fileSize,
        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
        'file_name'    => ensureVideoExt($fileName, $mimeType),
        'mime_type'    => $mimeType,
        'extension'    => $ext,
        'accessible'   => $accessible,
        'error'        => null,
    ];

    if (!$accessible)
        $result['error'] = $error ?: "Source HTTP {$code}";
    if ($fileSize > MAX_FILE_SIZE) {
        $result['error']      = 'File terlalu besar: ' . round($fileSize/1024/1024,2) . 'MB (maks 100MB)';
        $result['accessible'] = false;
    }
    if ($hMime && !isAmbiguous($hMime) && isNonVideo($hMime)) {
        $result['error']      = "Bukan file video. Content-Type: {$hMime}";
        $result['accessible'] = false;
    }

    Debug::log('PROBE_SRC', 'Source probed', ['status'=>$code,'size_mb'=>$result['file_size_mb'],'mime'=>$mimeType,'filename'=>$result['file_name']]);
    return $result;
}

// ── STEP 3: DOWNLOAD VIDEO ─────────────────────────────────────────────────────
function downloadVideo(string $url, string $fileName): array {
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'videy5_' . bin2hex(random_bytes(8));
    Debug::log('DOWNLOAD', 'Downloading video', ['url' => $url, 'tmp' => $tmpPath]);

    $fp = @fopen($tmpPath, 'wb');
    if (!$fp) return ['success' => false, 'error' => 'Gagal membuat file temp: ' . $tmpPath];

    $maxBytes      = MAX_FILE_SIZE;
    $bytesReceived = 0;
    $aborted       = false;
    $gMime         = ''; $gDisp = '';
    $startTime     = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT        => DOWNLOAD_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => [
            'Accept: video/*, application/octet-stream, */*;q=0.5',
            'Accept-Encoding: identity',
            'Sec-Fetch-Dest: video',
            'Sec-Fetch-Mode: no-cors',
        ],
        CURLOPT_BUFFERSIZE     => 65536,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use ($fp, $maxBytes, &$bytesReceived, &$aborted) {
            if ($aborted) return 0;
            $bytesReceived += strlen($data);
            if ($bytesReceived > $maxBytes) { $aborted = true; return 0; }
            fwrite($fp, $data);
            return strlen($data);
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $raw) use (&$gMime, &$gDisp) {
            $line = trim($raw);
            if (stripos($line, 'content-type:') === 0)
                $gMime = normMime(substr($line, 13));
            elseif (stripos($line, 'content-disposition:') === 0)
                $gDisp = substr($line, 20);
            return strlen($raw);
        },
    ]);
    applySslOpts($ch);
    curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    $elapsed  = round(microtime(true) - $startTime, 2);
    $fileSize = file_exists($tmpPath) ? filesize($tmpPath) : 0;
    $speedMBs = ($elapsed > 0 && $fileSize > 0) ? round($fileSize / 1024 / 1024 / $elapsed, 2) : 0;

    if ($aborted) {
        cleanupTemp($tmpPath);
        return ['success' => false, 'error' => 'File terlalu besar (>100MB). Download dibatalkan.'];
    }
    if ($curlErr) {
        cleanupTemp($tmpPath);
        return ['success' => false, 'error' => 'cURL error: ' . $curlErr];
    }
    if ($code < 200 || $code >= 300) {
        cleanupTemp($tmpPath);
        return ['success' => false, 'error' => "HTTP {$code} saat download"];
    }
    if ($fileSize < 1024) {
        cleanupTemp($tmpPath);
        return ['success' => false, 'error' => "File terlalu kecil ({$fileSize} bytes), kemungkinan gagal"];
    }

    // Refine filename from GET response headers
    if ($gDisp) { $fn2 = filenameFromDisposition($gDisp); if ($fn2) $fileName = $fn2; }

    Debug::log('DOWNLOAD', 'Download complete', ['size_mb'=>round($fileSize/1024/1024,2),'speed_mbs'=>$speedMBs,'elapsed_s'=>$elapsed]);
    return [
        'success'    => true,
        'tmp_file'   => $tmpPath,
        'file_name'  => $fileName,
        'file_size'  => $fileSize,
        'size_mb'    => round($fileSize / 1024 / 1024, 2),
        'speed_mbs'  => $speedMBs,
        'elapsed_s'  => $elapsed,
        'http_code'  => $code,
    ];
}

// ── STEP 4: UPLOAD TO VIDEY (with retry) ───────────────────────────────────────
function uploadToVidey(string $tmpFile, string $fileName, string $mimeType): array {
    Debug::log('UPLOAD', 'Uploading to Videy', ['file' => $fileName, 'mime' => $mimeType]);

    // Refine MIME via magic bytes before upload
    $magicMime = mimeFromMagic($tmpFile);
    if ($magicMime) $mimeType = $magicMime;
    if (!$mimeType || isAmbiguous($mimeType)) $mimeType = 'video/mp4';

    $cfile     = new CURLFile($tmpFile, $mimeType, $fileName);
    $uploadUrl = VIDEY_UPLOAD_URL;
    $lastErr   = '';

    for ($attempt = 0; $attempt <= UPLOAD_RETRY_MAX; $attempt++) {
        if ($attempt > 0) sleep(2);

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => $cfile],
            CURLOPT_TIMEOUT        => UPLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Origin: https://videy.co',
                'Referer: https://videy.co/',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);
        applySslOpts($ch);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Timeout → retry
        if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < UPLOAD_RETRY_MAX) {
            $lastErr = "Timeout (attempt " . ($attempt + 1) . ")";
            Debug::log('UPLOAD', 'Timeout, retrying', ['attempt' => $attempt + 1]);
            continue;
        }

        if ($err) {
            $lastErr = 'cURL: ' . $err;
            continue;
        }

        Debug::log('UPLOAD', 'Upload response', ['status' => $code, 'body_len' => strlen((string)$body), 'attempt' => $attempt + 1]);

        if ($code !== 200) {
            return [
                'success'      => false,
                'http_status'  => $code,
                'error'        => "HTTP {$code} dari Videy",
                'response_raw' => (string)$body,
                'attempts'     => $attempt + 1,
            ];
        }

        $r = json_decode((string)$body, true);
        if (!$r || empty($r['id'])) {
            return [
                'success'      => false,
                'http_status'  => $code,
                'error'        => 'Respons Videy tidak valid (id tidak ditemukan)',
                'response_raw' => substr((string)$body, 0, 300),
                'response_json'=> $r,
                'attempts'     => $attempt + 1,
            ];
        }

        $fileId    = $r['id'];
        $viewUrl   = VIDEY_VIEW_URL . $fileId;
        $cdnUrl    = VIDEY_CDN_URL  . $fileId . '.mp4';
        $directUrl = $cdnUrl;

        Debug::log('UPLOAD', 'Upload success', ['file_id' => $fileId, 'view_url' => $viewUrl]);
        return [
            'success'       => true,
            'http_status'   => $code,
            'file_id'       => $fileId,
            'file_key'      => $fileId,
            'view_url'      => $viewUrl,
            'direct_url'    => $directUrl,
            'cdn_url'       => $cdnUrl,
            'response_json' => $r,
            'attempts'      => $attempt + 1,
            'error'         => null,
        ];
    }

    return [
        'success'    => false,
        'http_status'=> 0,
        'error'      => $lastErr ?: 'Upload gagal setelah ' . (UPLOAD_RETRY_MAX + 1) . ' percobaan',
        'attempts'   => UPLOAD_RETRY_MAX + 1,
    ];
}

// ── STEP 5: VERIFY ─────────────────────────────────────────────────────────────
function verifyUpload(string $cdnUrl): array {
    Debug::log('VERIFY', 'Verifying CDN file', $cdnUrl);

    $ch = curl_init($cdnUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => ['Range: bytes=0-1023', 'Referer: https://videy.co/'],
        CURLOPT_HEADERFUNCTION => function ($ch, $raw) use (&$rh) {
            $len = strlen($raw);
            $h   = explode(':', $raw, 2);
            if (count($h) >= 2) $rh[strtolower(trim($h[0]))] = trim($h[1]);
            return $len;
        },
    ]);
    $rh = [];
    applySslOpts($ch);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $verified = in_array($code, [200, 206]);
    Debug::log('VERIFY', 'Verification result', ['status' => $code, 'verified' => $verified]);
    return [
        'verified'     => $verified,
        'http_status'  => $code,
        'content_type' => $rh['content-type'] ?? null,
        'file_size'    => $rh['content-length'] ?? null,
        'cf_cache'     => $rh['cf-cache-status'] ?? null,
        'cf_ray'       => $rh['cf-ray'] ?? null,
        'etag'         => $rh['etag'] ?? null,
        'last_modified'=> $rh['last-modified'] ?? null,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// MAIN
// ══════════════════════════════════════════════════════════════════════════════
Debug::init();

$response = [
    'success'    => false,
    'version'    => VERSION,
    'timestamp'  => date('c'),
    'request'    => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'url'    => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
    ],
    'mode'       => null,
    'steps'      => [],
    'result'     => null,
    'error'      => null,
    'debug_log'  => [],
    'elapsed_ms' => 0,
];

$inputUrl = trim($_GET['url'] ?? $_POST['url'] ?? '');
$hasFile  = isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;
$tmpFile  = null;

// ── Discovery mode ─────────────────────────────────────────────────────────────
if (empty($inputUrl) && !$hasFile) {
    $response['mode']    = 'discovery';
    $response['message'] = 'Tidak ada ?url= atau file. Menjalankan discovery mode.';
    $response['usage']   = [
        'endpoint' => 'https://upload.vidshare.my.id/apis1.php',
        'modes'    => [
            'url_upload'  => 'GET/POST ?url=VIDEO_URL',
            'file_upload' => 'POST multipart/form-data dengan field "file"',
        ],
        'example'  => 'https://upload.vidshare.my.id/apis1.php?url=https://example.com/video.mp4',
        'methods'  => ['GET', 'POST'],
        'max_size' => '100MB',
        'formats'  => 'mp4, mkv, avi, mov, wmv, flv, webm, 3gp, ogv, ts, m4v, mpeg',
    ];
    $response['discovery'] = discoverVideyApi();
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

        // STEP 1: Validate
        $validate = validateUploadedFile($_FILES['file']);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // STEP 2: File info (konsisten dengan url_upload)
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

        // STEP 3: Skip download — pakai PHP tmp file
        $tmpPath  = $validate['tmp_path'];
        $mimeType = detectMime($tmpPath, $validate['file_name']);
        if (!$mimeType || isAmbiguous($mimeType)) $mimeType = $validate['mime_type'];

        $response['steps']['3_download'] = [
            'source'    => 'direct_upload',
            'success'   => true,
            'tmp_file'  => $tmpPath,
            'file_size' => $validate['file_size'],
            'size_mb'   => $validate['file_size_mb'],
            'speed_mbs' => 'N/A',
            'elapsed_s' => 0,
        ];
        Debug::log('DOWNLOAD', 'Skipped — using PHP uploaded tmp file', ['tmp' => $tmpPath]);

        // STEP 4: Upload
        $upload = uploadToVidey($tmpPath, ensureVideoExt($validate['file_name'], $mimeType), $mimeType);
        $response['steps']['4_upload'] = $upload;
        if (!$upload['success']) {
            $response['error'] = $upload['error'] ?? 'Upload gagal (HTTP ' . $upload['http_status'] . ')';
            throw new RuntimeException($response['error']);
        }

        // STEP 5: Verify
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'file_id'     => $upload['file_id'],
            'file_key'    => $upload['file_key'],
            'view_url'    => $upload['view_url'],
            'direct_url'  => $upload['direct_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $validate['file_name'],
            'file_size'   => fmtSize($validate['file_size']),
            'file_size_mb'=> $validate['file_size_mb'],
            'mime_type'   => $mimeType,
            'verified'    => $verify['verified'],
            'source_url'  => null,
        ];
        Debug::log('DONE', 'File upload pipeline complete', ['file_id' => $upload['file_id']]);

    // ══════════════════════════════════════════════════════════════════════════
    // MODE B: URL UPLOAD
    // ══════════════════════════════════════════════════════════════════════════
    } else {
        $response['mode'] = 'url_upload';
        Debug::log('MODE', 'URL upload mode');

        // STEP 1: Validate URL
        $validate = validateVideoUrl($inputUrl);
        $response['steps']['1_validate'] = $validate;
        if (!$validate['valid']) {
            $response['error'] = $validate['error'];
            throw new RuntimeException($validate['error']);
        }

        // STEP 2: Probe source
        $probe = probeVideoSource($inputUrl);
        $response['steps']['2_probe_source'] = $probe;
        if (!$probe['accessible']) {
            $response['error'] = $probe['error'] ?? 'Source tidak bisa diakses';
            throw new RuntimeException($response['error']);
        }

        // STEP 3: Download
        $download = downloadVideo($inputUrl, $probe['file_name']);
        $response['steps']['3_download'] = $download;
        $tmpFile  = $download['tmp_file'] ?? null;
        if (!$download['success']) {
            $response['error'] = $download['error'];
            throw new RuntimeException($response['error']);
        }

        // Refine mime from downloaded file
        $mimeType = detectMime($download['tmp_file'], $download['file_name'] ?? $probe['file_name']);
        if (!$mimeType || isAmbiguous($mimeType)) $mimeType = $probe['mime_type'];
        $fileName = ensureVideoExt($download['file_name'] ?? $probe['file_name'], $mimeType);

        // STEP 4: Upload
        $upload = uploadToVidey($download['tmp_file'], $fileName, $mimeType);
        $response['steps']['4_upload'] = $upload;

        // Cleanup temp setelah upload selesai (berhasil atau gagal)
        if ($tmpFile && file_exists($tmpFile)) {
            @unlink($tmpFile);
            $tmpFile = null;
            Debug::log('CLEANUP', 'Temp file deleted');
        }

        if (!$upload['success']) {
            $response['error'] = $upload['error'] ?? 'Upload gagal (HTTP ' . $upload['http_status'] . ')';
            throw new RuntimeException($response['error']);
        }

        // STEP 5: Verify
        $verify = verifyUpload($upload['cdn_url']);
        $response['steps']['5_verify'] = $verify;

        $response['success'] = true;
        $response['result']  = [
            'file_id'     => $upload['file_id'],
            'file_key'    => $upload['file_key'],
            'view_url'    => $upload['view_url'],
            'direct_url'  => $upload['direct_url'],
            'cdn_url'     => $upload['cdn_url'],
            'file_name'   => $fileName,
            'file_size'   => fmtSize($download['file_size']),
            'file_size_mb'=> $download['size_mb'],
            'mime_type'   => $mimeType,
            'verified'    => $verify['verified'],
            'source_url'  => $inputUrl,
        ];
        Debug::log('DONE', 'URL upload pipeline complete', ['file_id' => $upload['file_id']]);
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
