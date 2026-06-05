<?php
// ─── AJAX HANDLER ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $serverMap = [
        'ace'   => 'apiace.php',
        'sd'    => 'apisd.php',
        'videy' => 'apivid.php',
        'zig'   => 'apizig.php',
    ];

    $server  = $_GET['server'] ?? '';
    $mode    = $_GET['mode']   ?? 'url';
    $index   = (int)($_GET['index'] ?? 0);
    $itemUrl = trim($_POST['url'] ?? '');

    if (!isset($serverMap[$server])) {
        echo json_encode(['success'=>false,'error'=>'Unknown server: '.$server]); exit;
    }
    $apiFile = __DIR__.'/'.$serverMap[$server];
    if (!file_exists($apiFile)) {
        echo json_encode(['success'=>false,'error'=>'File tidak ditemukan: '.$serverMap[$server]]); exit;
    }

    $scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $baseUrl = $scheme.'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['REQUEST_URI']),'/').'/'.$serverMap[$server];

    if ($mode === 'url') {
        if (empty($itemUrl)) { echo json_encode(['success'=>false,'error'=>'URL kosong']); exit; }
        $ch = curl_init($baseUrl.'?url='.urlencode($itemUrl));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>300,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_FOLLOWLOCATION=>true]);
        $body = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        if ($cerr) { echo json_encode(['success'=>false,'error'=>'cURL: '.$cerr]); exit; }
        $json = json_decode($body, true);
        if ($json === null) { echo json_encode(['success'=>false,'error'=>'Response bukan JSON','raw'=>substr($body,0,400)]); exit; }
        echo json_encode(normalizeResult($json,$server,$index)); exit;
    }

    if ($mode === 'file') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['success'=>false,'error'=>'Tidak ada file']); exit;
        }
        $cfile = new CURLFile($_FILES['file']['tmp_name'], $_FILES['file']['type'], $_FILES['file']['name']);
        $ch = curl_init($baseUrl);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>$cfile],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>300,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_FOLLOWLOCATION=>true]);
        $body = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        if ($cerr) { echo json_encode(['success'=>false,'error'=>'cURL: '.$cerr]); exit; }
        $json = json_decode($body, true);
        if ($json === null) { echo json_encode(['success'=>false,'error'=>'Response bukan JSON','raw'=>substr($body,0,400)]); exit; }
        echo json_encode(normalizeResult($json,$server,$index)); exit;
    }

    echo json_encode(['success'=>false,'error'=>'Invalid mode']); exit;
}

function normalizeResult(array $j, string $srv, int $idx): array {
    $ok  = $j['success'] ?? false;
    $res = $j['result']  ?? [];
    if (!$ok || empty($res)) return ['success'=>false,'server'=>$srv,'index'=>$idx,'error'=>$j['error']??'Upload gagal'];
    $urls=[];
    if (!empty($res['view_url']))       $urls['view']   = $res['view_url'];
    if (!empty($res['direct_url']))     $urls['direct'] = $res['direct_url'];
    if (!empty($res['cdn_url']))        $urls['cdn']    = $res['cdn_url'];
    if (!empty($res['watch_url']))      $urls['watch']  = $res['watch_url'];
    if (!empty($res['urls']['share']))  $urls['share']  = $res['urls']['share'];
    if (!empty($res['urls']['stream'])) $urls['stream'] = $res['urls']['stream'];
    if (!empty($res['urls']['file']))   $urls['file']   = $res['urls']['file'];
    if (!empty($res['urls']['embed']))  $urls['embed']  = $res['urls']['embed'];
    $primary = $urls['share']??$urls['view']??$urls['watch']??$urls['cdn']??$urls['direct']??$urls['stream']??$urls['file']??null;
    return [
        'success'   => true, 'server' => $srv, 'index' => $idx,
        'primary'   => $primary, 'urls'  => $urls,
        'file_name' => $res['file_name'] ?? ($res['original_name'] ?? ($res['title'] ?? '')),
        'size_mb'   => $res['file_size_mb'] ?? ($res['size_mb'] ?? 0),
    ];
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bulk Video Uploader — 4 Server</title>
<style>
:root{
  --bg:#0f1117; --sur:#1a1d27; --card:#21263a; --bdr:#2e3450;
  --acc:#5b7cff; --acc2:#7c3aed; --grn:#22c55e; --red:#ef4444;
  --ylw:#f59e0b; --txt:#e2e8f0; --mut:#64748b; --r:10px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--txt);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;min-height:100vh}
a{color:var(--acc);text-decoration:none}a:hover{text-decoration:underline}

/* HEADER */
header{background:linear-gradient(135deg,#1a1d27,#151824);border-bottom:1px solid var(--bdr);padding:14px 24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
header h1{font-size:18px;font-weight:700}header p{font-size:12px;color:var(--mut)}
.badges{margin-left:auto;display:flex;gap:5px;flex-wrap:wrap}
.badge{font-size:11px;padding:2px 9px;border-radius:999px;font-weight:700;color:#fff}
.b1{background:#5b7cff}.b2{background:#7c3aed}.b3{background:#059669}.b4{background:#d97706}

/* LAYOUT */
.wrap{max-width:1060px;margin:0 auto;padding:20px 14px}

/* TABS */
.tabs{display:flex;gap:4px;background:var(--sur);padding:4px;border-radius:var(--r);border:1px solid var(--bdr);width:fit-content;margin-bottom:18px}
.tab-btn{padding:8px 22px;border:none;background:transparent;color:var(--mut);border-radius:7px;cursor:pointer;font-size:14px;font-weight:500;transition:.15s}
.tab-btn.active{background:var(--acc);color:#fff}
.tab-btn:hover:not(.active){color:var(--txt);background:var(--card)}

/* PANEL */
.panel{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r);padding:18px;margin-bottom:14px}
.ptitle{font-size:15px;font-weight:600;margin-bottom:12px}

/* DROPZONE */
.dzone{border:2px dashed var(--bdr);border-radius:var(--r);padding:28px 16px;text-align:center;cursor:pointer;transition:.2s;user-select:none}
.dzone:hover,.dzone.over{border-color:var(--acc);background:rgba(91,124,255,.07)}
.dzone .ico{font-size:32px;margin-bottom:8px}
.dzone p{color:var(--mut);font-size:13px}.dzone strong{color:var(--txt)}

/* ITEM LIST */
.ilist{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.irow{background:var(--card);border:1px solid var(--bdr);border-radius:8px;padding:8px 11px;display:flex;align-items:center;gap:8px}
.irow .ico{font-size:18px;flex-shrink:0}
.irow .inf{flex:1;min-width:0}
.irow .nm{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}
.irow .mt{font-size:11px;color:var(--mut);margin-top:1px}
.rmbtn{background:transparent;border:none;color:var(--red);cursor:pointer;font-size:15px;padding:2px 7px;border-radius:4px;flex-shrink:0;line-height:1}
.rmbtn:hover{background:rgba(239,68,68,.12)}

/* TEXTAREA */
.url-ta{width:100%;background:var(--card);border:1px solid var(--bdr);border-radius:8px;padding:10px 12px;color:var(--txt);font-size:13px;resize:vertical;min-height:90px;font-family:inherit}
.url-ta:focus{outline:none;border-color:var(--acc)}
.url-ta::placeholder{color:var(--mut)}

/* ACTION BAR */
.actbar{display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap}
.bcnt{font-size:12px;color:var(--mut);background:var(--card);border:1px solid var(--bdr);padding:4px 10px;border-radius:6px}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:5px;padding:9px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;transition:.15s;white-space:nowrap;line-height:1}
.btn-up{background:var(--acc);color:#fff}
.btn-up:hover:not(:disabled){background:#4a6bef;transform:translateY(-1px);box-shadow:0 3px 10px rgba(91,124,255,.35)}
.btn-up:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none}
.btn-sec{background:var(--card);color:var(--txt);border:1px solid var(--bdr)}
.btn-sec:hover:not(:disabled){background:var(--bdr)}
.btn-add{background:var(--acc2);color:#fff}
.btn-add:hover{background:#6d28d9}
.btn-sm{padding:6px 13px;font-size:12px}

/* ═══ PROGRESS AREA ═══ */
#prog-wrap{display:none}

.prog-box{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r);padding:18px;margin-bottom:14px}

/* Global progress */
.glob-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.glob-hdr h2{font-size:15px;font-weight:600}
.glob-stat{font-size:13px;color:var(--mut)}
.glob-bar{height:8px;background:var(--card);border-radius:999px;overflow:hidden;margin-bottom:6px}
.glob-fill{height:100%;background:linear-gradient(90deg,var(--acc),var(--acc2));border-radius:999px;transition:width .4s;width:0%}
.glob-sub{font-size:12px;color:var(--mut);margin-bottom:18px}

/* Server progress pills */
.srv-pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.srv-pill{display:flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--bdr);border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;min-width:130px}
.srv-pill .p-name{flex:1}
.srv-pill .p-stat{font-size:11px;font-weight:400}
.srv-pill .p-cnt{font-size:11px;color:var(--mut);margin-left:auto}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.d-idle{background:var(--mut)}
.d-run{background:var(--ylw);animation:pulse 1s infinite}
.d-ok{background:var(--grn)}
.d-fail{background:var(--red)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.2}}

/* Log */
.log-title{font-size:12px;font-weight:700;color:var(--mut);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px}
.log-box{background:var(--card);border:1px solid var(--bdr);border-radius:8px;padding:10px 12px;max-height:180px;overflow-y:auto;font-size:12px;font-family:'Courier New',monospace;line-height:1.7}
.log-box:empty::before{content:'Menunggu upload dimulai…';color:var(--mut)}
.log-line{display:flex;gap:8px;align-items:baseline}
.log-time{color:var(--mut);flex-shrink:0;font-size:11px}
.log-ok  {color:var(--grn)}
.log-err {color:var(--red)}
.log-run {color:var(--ylw)}
.log-inf {color:var(--txt)}

/* RESULT CARDS */
.res-grid{display:flex;flex-direction:column;gap:12px}
.res-card{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden}
.res-hdr{padding:10px 14px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:9px}
.res-hdr .fn{font-weight:600;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}
.res-hdr .fm{font-size:11px;color:var(--mut)}
.copy-all-btn{background:var(--card);border:1px solid var(--bdr);color:var(--txt);border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;white-space:nowrap;transition:.15s}
.copy-all-btn:hover{border-color:var(--acc);color:var(--acc)}

/* SERVER ROWS inside card */
.srows{padding:3px 0}
.srow{display:flex;align-items:center;gap:9px;padding:7px 14px;border-bottom:1px solid rgba(46,52,80,.4);min-height:38px}
.srow:last-child{border-bottom:none}
.sn{width:86px;font-size:12px;font-weight:700;flex-shrink:0;display:flex;align-items:center;gap:5px}
.ss{width:76px;font-size:12px;flex-shrink:0}
.s-wait{color:var(--mut)}.s-run{color:var(--ylw)}.s-ok{color:var(--grn)}.s-fail{color:var(--red)}
.su{flex:1;font-size:12px;min-width:0}
.sa{display:flex;gap:5px;flex-shrink:0;align-items:center}
.url-sel{background:var(--card);border:1px solid var(--bdr);color:var(--txt);border-radius:5px;padding:3px 6px;font-size:11px;cursor:pointer;outline:none;max-width:145px}
.url-sel:focus{border-color:var(--acc)}
.cpbtn{background:var(--card);border:1px solid var(--bdr);color:var(--mut);border-radius:5px;padding:3px 10px;font-size:11px;cursor:pointer;transition:.15s}
.cpbtn:hover{border-color:var(--acc);color:var(--acc)}
.cpbtn.ok{border-color:var(--grn);color:var(--grn)}

/* SUMMARY */
.sum-box{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--r);padding:16px;margin-bottom:14px}
.sum-box h3{font-size:14px;font-weight:700;margin-bottom:12px}
.sum-grid{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:13px}
.sc{background:var(--card);border:1px solid var(--bdr);border-radius:8px;padding:10px 14px;flex:1;min-width:140px}
.sc h4{font-size:12px;font-weight:700;margin-bottom:5px;display:flex;justify-content:space-between}
.scnt{font-size:12px;display:flex;gap:8px}
.cok{color:var(--grn)}.cfail{color:var(--red)}
.sum-acts{display:flex;gap:7px;flex-wrap:wrap}

/* TOAST */
#toast{position:fixed;bottom:20px;right:20px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;opacity:0;transform:translateY(8px);transition:.2s;z-index:9999;pointer-events:none;color:#fff;background:var(--grn)}
#toast.show{opacity:1;transform:translateY(0)}
#toast.err{background:var(--red)}
</style>
</head>
<body>

<header>
  <div style="font-size:24px">🎬</div>
  <div><h1>Bulk Video Uploader</h1><p>Upload simultan ke 4 server — independen satu sama lain</p></div>
  <div class="badges">
    <span class="badge b1">AceImg</span>
    <span class="badge b2">SliceDrive</span>
    <span class="badge b3">Videy</span>
    <span class="badge b4">Zig.ht</span>
  </div>
</header>

<div class="wrap">

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" id="tb-file" onclick="switchTab('file')">📁 Upload File</button>
    <button class="tab-btn" id="tb-url"  onclick="switchTab('url')">🔗 Upload via URL</button>
  </div>

  <!-- FILE TAB -->
  <div id="tab-file">
    <div class="panel">
      <div class="ptitle">Pilih File Video</div>
      <div class="dzone" id="dz" onclick="document.getElementById('fi').click()"
           ondragover="dzOver(event)" ondragleave="dzLeave(event)" ondrop="dzDrop(event)">
        <div class="ico">🎥</div>
        <p><strong>Klik di sini</strong> atau <strong>drag &amp; drop</strong> file video</p>
        <p style="margin-top:5px">MP4 · WebM · MOV · AVI · MKV · FLV · TS · M4V — maks 500 MB/file</p>
      </div>
      <input type="file" id="fi" multiple accept="video/*,.mp4,.webm,.mov,.avi,.mkv,.flv,.ts,.m4v"
             style="display:none" onchange="onFileSel(event)">
      <div class="ilist" id="file-list"></div>
      <div class="actbar" id="file-acts" style="display:none">
        <button class="btn btn-up" id="btn-fu" onclick="startUpload('file')">🚀 Upload Sekarang</button>
        <button class="btn btn-sec btn-sm" onclick="clearItems('file')">🗑 Hapus Semua</button>
        <span class="bcnt" id="file-bcnt"></span>
      </div>
    </div>
  </div>

  <!-- URL TAB -->
  <div id="tab-url" style="display:none">
    <div class="panel">
      <div class="ptitle">Masukkan URL Video</div>
      <textarea class="url-ta" id="url-ta"
        placeholder="Tempel URL video, satu per baris...&#10;https://example.com/video1.mp4&#10;https://example.com/video2.mp4"></textarea>
      <div class="actbar">
        <button class="btn btn-add btn-sm" onclick="addUrls()">➕ Tambah ke Daftar</button>
        <button class="btn btn-up btn-sm" onclick="addAndUpload()">⚡ Langsung Upload</button>
      </div>
      <div class="ilist" id="url-list"></div>
      <div class="actbar" id="url-acts" style="display:none">
        <button class="btn btn-up" id="btn-uu" onclick="startUpload('url')">🚀 Upload Sekarang</button>
        <button class="btn btn-sec btn-sm" onclick="clearItems('url')">🗑 Hapus Semua</button>
        <span class="bcnt" id="url-bcnt"></span>
      </div>
    </div>
  </div>

  <!-- ═══ PROGRESS AREA ═══ -->
  <div id="prog-wrap">

    <!-- Progress panel -->
    <div class="prog-box">
      <div class="glob-hdr">
        <h2>⏳ Progress Upload</h2>
        <span class="glob-stat" id="glob-stat">Siap</span>
      </div>
      <div class="glob-bar"><div class="glob-fill" id="glob-fill"></div></div>
      <div class="glob-sub" id="glob-sub">Menunggu…</div>

      <!-- Per-server pills -->
      <div class="srv-pills" id="srv-pills"></div>

      <!-- Log -->
      <div class="log-title">📋 Log Aktivitas</div>
      <div class="log-box" id="log-box"></div>
    </div>

    <!-- Result cards -->
    <div class="res-grid" id="res-grid"></div>

    <!-- Summary -->
    <div class="sum-box" id="sum-box" style="display:none">
      <h3>📊 Ringkasan</h3>
      <div class="sum-grid" id="sum-grid"></div>
      <div class="sum-acts">
        <button class="btn btn-sec btn-sm" onclick="copyAllPrimary()">📋 Copy Semua URL</button>
        <button class="btn btn-sec btn-sm" onclick="copyByServer('ace')">Copy AceImg</button>
        <button class="btn btn-sec btn-sm" onclick="copyByServer('sd')">Copy SliceDrive</button>
        <button class="btn btn-sec btn-sm" onclick="copyByServer('videy')">Copy Videy</button>
        <button class="btn btn-sec btn-sm" onclick="copyByServer('zig')">Copy Zig.ht</button>
      </div>
    </div>

  </div><!-- /prog-wrap -->

</div><!-- /wrap -->
<div id="toast"></div>

<script>
// ═══ CONFIG ═══════════════════════════════════════════════════════════════════
const SRVS = [
  {id:'ace',   label:'AceImg',     color:'#5b7cff'},
  {id:'sd',    label:'SliceDrive', color:'#7c3aed'},
  {id:'videy', label:'Videy',      color:'#059669'},
  {id:'zig',   label:'Zig.ht',     color:'#d97706'},
];

let fileItems = [], urlItems = [], results = {}, idCtr = 0, busy = false;

// ═══ TABS ═════════════════════════════════════════════════════════════════════
function switchTab(t) {
  document.getElementById('tab-file').style.display = t==='file' ? '' : 'none';
  document.getElementById('tab-url').style.display  = t==='url'  ? '' : 'none';
  document.getElementById('tb-file').classList.toggle('active', t==='file');
  document.getElementById('tb-url').classList.toggle('active',  t==='url');
}

// ═══ FILE ═════════════════════════════════════════════════════════════════════
function onFileSel(e) { addFiles([...e.target.files]); e.target.value = ''; }
function dzOver(e)  { e.preventDefault(); document.getElementById('dz').classList.add('over'); }
function dzLeave(e) { document.getElementById('dz').classList.remove('over'); }
function dzDrop(e) {
  e.preventDefault();
  document.getElementById('dz').classList.remove('over');
  const vids = [...e.dataTransfer.files].filter(f => f.type.startsWith('video/') || /\.(mp4|webm|mov|avi|mkv|flv|ts|m4v)$/i.test(f.name));
  addFiles(vids);
}
function addFiles(files) {
  files.forEach(f => {
    if (!fileItems.find(x => x.file.name===f.name && x.file.size===f.size))
      fileItems.push({file: f, id: ++idCtr});
  });
  renderList('file');
}
function removeFile(id) { fileItems = fileItems.filter(x => x.id!==id); renderList('file'); }

// ═══ URL ══════════════════════════════════════════════════════════════════════
function addUrls() {
  const ta = document.getElementById('url-ta');
  ta.value.split('\n').map(s=>s.trim()).filter(s=>s.length>7).forEach(url => {
    if (!urlItems.find(x => x.url===url)) urlItems.push({url, id: ++idCtr});
  });
  ta.value = '';
  renderList('url');
}
function addAndUpload() { addUrls(); if (urlItems.length>0) startUpload('url'); }
function removeUrl(id) { urlItems = urlItems.filter(x => x.id!==id); renderList('url'); }

// ═══ RENDER LIST ══════════════════════════════════════════════════════════════
function renderList(mode) {
  const isF  = mode==='file';
  const arr  = isF ? fileItems : urlItems;
  const list = document.getElementById(isF ? 'file-list' : 'url-list');
  const acts = document.getElementById(isF ? 'file-acts' : 'url-acts');
  const bcnt = document.getElementById(isF ? 'file-bcnt' : 'url-bcnt');

  if (!arr.length) { list.innerHTML=''; acts.style.display='none'; return; }
  acts.style.display = '';
  bcnt.textContent = arr.length+' item × 4 server = '+(arr.length*4)+' upload';

  list.innerHTML = arr.map(it => {
    const name = isF ? it.file.name : it.url;
    const meta = isF ? fmtSz(it.file.size) : 'URL Video';
    const rmFn = isF ? `removeFile(${it.id})` : `removeUrl(${it.id})`;
    return `<div class="irow">
      <div class="ico">${isF?'🎬':'🔗'}</div>
      <div class="inf">
        <div class="nm" title="${esc(name)}">${esc(shorten(name,68))}</div>
        <div class="mt">${meta}</div>
      </div>
      <button class="rmbtn" onclick="${rmFn}">✕</button>
    </div>`;
  }).join('');
}

function clearItems(mode) {
  if (mode==='file') { fileItems=[]; renderList('file'); }
  else               { urlItems=[]; renderList('url'); }
}

// ═══ SERVER PILLS ════════════════════════════════════════════════════════════
let pillStats = {}; // {srvId: {done,ok,fail,total}}

function initPills(total) {
  pillStats = {};
  SRVS.forEach(s => { pillStats[s.id] = {done:0, ok:0, fail:0, total}; });
  const c = document.getElementById('srv-pills');
  c.innerHTML = SRVS.map(s => `
    <div class="srv-pill" id="pill-${s.id}" style="border-color:${s.color}22">
      <span class="dot d-idle" id="pdot-${s.id}"></span>
      <span class="p-name" style="color:${s.color}">${s.label}</span>
      <span class="p-stat s-wait" id="pst-${s.id}">Menunggu</span>
      <span class="p-cnt" id="pcnt-${s.id}">0/${total}</span>
    </div>`).join('');
}

function updatePill(srvId) {
  const ps = pillStats[srvId];
  const dot = document.getElementById('pdot-'+srvId);
  const st  = document.getElementById('pst-'+srvId);
  const cnt = document.getElementById('pcnt-'+srvId);
  if (!dot) return;
  cnt.textContent = ps.done+'/'+ps.total;
  if (ps.done === 0) {
    dot.className='dot d-idle'; st.className='p-stat s-wait'; st.textContent='Menunggu';
  } else if (ps.done < ps.total) {
    dot.className='dot d-run'; st.className='p-stat s-run'; st.textContent='Uploading…';
  } else {
    dot.className='dot '+(ps.fail===0?'d-ok':'d-fail');
    st.className='p-stat '+(ps.fail===0?'s-ok':'s-fail');
    st.textContent = ps.fail===0 ? '✓ Selesai' : `✓${ps.ok} ✕${ps.fail}`;
  }
}

// ═══ LOG ══════════════════════════════════════════════════════════════════════
function addLog(msg, type='inf') {
  const box = document.getElementById('log-box');
  const now = new Date();
  const ts  = now.toTimeString().slice(0,8)+'.'+String(now.getMilliseconds()).padStart(3,'0');
  const div = document.createElement('div');
  div.className = 'log-line';
  div.innerHTML = `<span class="log-time">[${ts}]</span><span class="log-${type}">${esc(msg)}</span>`;
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

// ═══ UPLOAD ══════════════════════════════════════════════════════════════════
async function startUpload(mode) {
  if (busy) { toast('Upload sedang berjalan…', true); return; }
  const items = mode==='file' ? fileItems : urlItems;
  if (!items.length) { toast('Tidak ada item!', true); return; }

  busy = true;
  results = {};
  document.getElementById('btn-fu').disabled = true;
  document.getElementById('btn-uu').disabled = true;

  // Show progress area
  const pw = document.getElementById('prog-wrap');
  pw.style.display = 'block';
  document.getElementById('sum-box').style.display = 'none';
  document.getElementById('res-grid').innerHTML = '';
  document.getElementById('log-box').innerHTML = '';

  const total = items.length;
  initPills(total);

  addLog(`Mulai upload: ${total} item × 4 server = ${total*4} proses`, 'inf');

  // Build skeleton cards
  items.forEach((it, idx) => {
    results[it.id] = {};
    SRVS.forEach(s => { results[it.id][s.id] = {status:'waiting'}; });
    buildCard(it, mode);
  });

  // Scroll to progress
  pw.scrollIntoView({behavior:'smooth', block:'start'});

  let totalTasks  = total * SRVS.length;
  let doneTasks   = 0;
  let okTasks     = 0;
  let failTasks   = 0;

  setGlob(0, totalTasks, okTasks, failTasks);

  // Launch all concurrently
  const tasks = SRVS.flatMap(srv =>
    items.map((it, idx) =>
      doUpload(mode, it, srv, idx).then(r => {
        results[it.id][srv.id] = r;
        updateRow(it.id, srv.id, r);

        doneTasks++;
        if (r.success) okTasks++; else failTasks++;

        // update pill
        pillStats[srv.id].done++;
        if (r.success) pillStats[srv.id].ok++; else pillStats[srv.id].fail++;
        updatePill(srv.id);

        setGlob(doneTasks, totalTasks, okTasks, failTasks);

        const fname = mode==='file' ? it.file.name : shorten(it.url,40);
        if (r.success) {
          addLog(`✓ [${srv.label}] ${fname} → ${r.primary||'URL tidak diketahui'}`, 'ok');
        } else {
          addLog(`✕ [${srv.label}] ${fname} — ${r.error||'Gagal'}`, 'err');
        }

        if (doneTasks === totalTasks) finalize(items);
      })
    )
  );

  await Promise.allSettled(tasks);
  busy = false;
  document.getElementById('btn-fu').disabled = false;
  document.getElementById('btn-uu').disabled = false;
}

async function doUpload(mode, item, srv, idx) {
  const fname = mode==='file' ? item.file.name : shorten(item.url, 40);
  addLog(`▶ [${srv.label}] mulai → ${fname}`, 'run');
  updateRow(item.id, srv.id, {status:'loading'});
  try {
    const fd = new FormData();
    const qs = new URLSearchParams({ajax:'1', server:srv.id, mode, index:idx});
    if (mode==='file') fd.append('file', item.file, item.file.name);
    else               fd.append('url', item.url);

    const res = await fetch(window.location.pathname+'?'+qs, {method:'POST', body:fd});
    if (!res.ok) throw new Error('HTTP '+res.status+' dari server');
    const json = await res.json();
    // attach raw for debug
    json._raw_ok = true;
    return json;
  } catch(e) {
    return {success:false, server:srv.id, index:idx, error:e.message};
  }
}

// ═══ GLOBAL PROGRESS ═════════════════════════════════════════════════════════
function setGlob(done, total, ok, fail) {
  const pct = total ? Math.round(done/total*100) : 0;
  document.getElementById('glob-fill').style.width = pct+'%';
  document.getElementById('glob-stat').textContent = `${done}/${total} (${pct}%)`;
  document.getElementById('glob-sub').textContent =
    done===total
      ? `Selesai! ✓ ${ok} sukses  ✕ ${fail} gagal`
      : `${done} selesai dari ${total} — ✓ ${ok} sukses  ✕ ${fail} gagal`;
}

// ═══ RESULT CARD ═════════════════════════════════════════════════════════════
function buildCard(item, mode) {
  const grid = document.getElementById('res-grid');
  const name = mode==='file' ? item.file.name : item.url;
  const meta = mode==='file' ? fmtSz(item.file.size) : 'URL';
  const div  = document.createElement('div');
  div.className = 'res-card';
  div.id = 'card-'+item.id;
  div.innerHTML = `
    <div class="res-hdr">
      <div style="font-size:17px">${mode==='file'?'🎬':'🔗'}</div>
      <div style="flex:1;min-width:0">
        <div class="fn" title="${esc(name)}">${esc(shorten(name,65))}</div>
        <div class="fm">${meta}</div>
      </div>
      <button class="copy-all-btn" onclick="copyItem(${item.id})">📋 Copy Semua</button>
    </div>
    <div class="srows" id="rows-${item.id}">
      ${SRVS.map(s=>rowHTML(item.id,s,{status:'waiting'})).join('')}
    </div>`;
  grid.appendChild(div);
}

function rowHTML(itemId, srv, st) {
  const dc  = st.status==='waiting'?'d-idle': st.status==='loading'?'d-run': st.success?'d-ok':'d-fail';
  const sc  = st.status==='waiting'?'s-wait': st.status==='loading'?'s-run': st.success?'s-ok':'s-fail';
  const stx = st.status==='waiting'?'Menunggu': st.status==='loading'?'Uploading…': st.success?'✓ Sukses':'✕ Gagal';

  let urlPart = `<span style="color:var(--mut);font-size:11px">—</span>`;
  let actPart = '';

  if (st.success && st.primary) {
    const opts = Object.entries(st.urls||{}).filter(([,v])=>v)
      .map(([k,v])=>`<option value="${esc(v)}">${k}: ${esc(shorten(v,38))}</option>`).join('');
    urlPart = `<select class="url-sel" id="sel-${itemId}-${srv.id}">${opts}</select>`;
    actPart = `<div class="sa">
      <button class="cpbtn" id="cpb-${itemId}-${srv.id}" onclick="cpSel(${itemId},'${srv.id}')">📋 Copy</button>
      <a href="${esc(st.primary)}" target="_blank" title="Buka" style="font-size:16px;color:var(--acc)">↗</a>
    </div>`;
  } else if (!st.success && st.status!=='waiting' && st.status!=='loading') {
    urlPart = `<span style="color:var(--red);font-size:11px" title="${esc(st.error||'')}">⚠ ${esc(trunc(st.error||'Error',52))}</span>`;
  }

  return `<div class="srow" id="row-${itemId}-${srv.id}">
    <div class="sn" style="color:${srv.color}">
      <span class="dot ${dc}" id="rdot-${itemId}-${srv.id}"></span>${srv.label}
    </div>
    <div class="ss ${sc}" id="rst-${itemId}-${srv.id}">${stx}</div>
    <div class="su">${urlPart}</div>
    ${actPart}
  </div>`;
}

function updateRow(itemId, srvId, st) {
  const row = document.getElementById('row-'+itemId+'-'+srvId);
  const srv = SRVS.find(s => s.id===srvId);
  if (row && srv) row.outerHTML = rowHTML(itemId, srv, st);
}

// ═══ COPY ════════════════════════════════════════════════════════════════════
function cpSel(itemId, srvId) {
  const sel = document.getElementById('sel-'+itemId+'-'+srvId);
  if (!sel) return;
  cpText(sel.value);
  const b = document.getElementById('cpb-'+itemId+'-'+srvId);
  if (b) { b.textContent='✅'; b.classList.add('ok'); setTimeout(()=>{b.textContent='📋 Copy';b.classList.remove('ok');},1500); }
}
function copyItem(itemId) {
  const lines = SRVS.map(s => {
    const r = results[itemId]?.[s.id];
    return r&&r.success&&r.primary ? `[${s.label}] ${r.primary}` : null;
  }).filter(Boolean);
  if (!lines.length) { toast('Belum ada URL sukses untuk item ini', true); return; }
  cpText(lines.join('\n')); toast('✅ '+lines.length+' URL disalin!');
}
function copyAllPrimary() {
  const lines=[];
  Object.values(results).forEach(sm => SRVS.forEach(s => {
    const r=sm[s.id]; if(r&&r.success&&r.primary) lines.push(`[${s.label}] ${r.primary}`);
  }));
  if (!lines.length) { toast('Belum ada URL sukses', true); return; }
  cpText(lines.join('\n')); toast('✅ '+lines.length+' URL disalin!');
}
function copyByServer(srvId) {
  const srv = SRVS.find(s=>s.id===srvId);
  const lines = Object.values(results).map(sm => sm[srvId]).filter(r=>r&&r.success&&r.primary).map(r=>r.primary);
  if (!lines.length) { toast('Belum ada URL sukses untuk '+srv.label, true); return; }
  cpText(lines.join('\n')); toast('✅ '+lines.length+' URL '+srv.label+' disalin!');
}
function cpText(text) {
  if (navigator.clipboard) navigator.clipboard.writeText(text);
  else { const t=document.createElement('textarea'); t.value=text; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); }
}

// ═══ FINALIZE ════════════════════════════════════════════════════════════════
function finalize(items) {
  const sb = document.getElementById('sum-box');
  sb.style.display = '';
  document.getElementById('sum-grid').innerHTML = SRVS.map(s => {
    const rs = items.map(it=>results[it.id]?.[s.id]).filter(Boolean);
    const ok = rs.filter(r=>r.success).length;
    const er = rs.filter(r=>!r.success&&r.status!=='waiting'&&r.status!=='loading').length;
    return `<div class="sc">
      <h4 style="color:${s.color}">${s.label}<span style="color:var(--mut);font-weight:400">${ok}/${items.length}</span></h4>
      <div class="scnt"><span class="cok">✓ ${ok} sukses</span>${er?`<span class="cfail">✕ ${er} gagal</span>`:''}</div>
    </div>`;
  }).join('');
  addLog('━━━ Semua upload selesai ━━━', 'inf');
  sb.scrollIntoView({behavior:'smooth', block:'start'});
}

// ═══ UTILS ═══════════════════════════════════════════════════════════════════
function toast(msg, isErr=false) {
  const t=document.getElementById('toast');
  t.textContent=msg; t.className='show'+(isErr?' err':'');
  clearTimeout(t._t); t._t=setTimeout(()=>t.className='',2600);
}
function fmtSz(b) {
  if(b>1<<30) return (b/(1<<30)).toFixed(2)+' GB';
  if(b>1<<20) return (b/(1<<20)).toFixed(2)+' MB';
  if(b>1<<10) return (b/(1<<10)).toFixed(1)+' KB';
  return b+' B';
}
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function shorten(s,n) { s=String(s||''); return s.length<=n?s:s.slice(0,n-1)+'…'; }
function trunc(s,n)   { s=String(s||''); return s.length<=n?s:s.slice(0,n)+'…'; }
</script>
</body>
</html>
