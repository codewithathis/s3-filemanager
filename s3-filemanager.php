<?php
/**
 * s3-tiny-file-manager.php
 *
 * Single-file PHP + JavaScript tiny S3 file manager.
 * Requirements:
 *  - PHP 7.4+
 *  - Composer
 *  - aws/aws-sdk-php (composer require aws/aws-sdk-php)
 *
 * Usage:
 *  1. Place this file on a PHP-capable webserver (e.g. Apache, nginx+php-fpm).
 *  2. Create a credentials.ini file in the same directory with this structure:
 *
 *     [default]
 *     aws_access_key_id = YOUR_KEY
 *     aws_secret_access_key = YOUR_SECRET
 *     region = us-east-1
 *     endpoint = 
 *
 *    (endpoint optional: use for non-AWS S3-compatible services such as MinIO)
 *
 *  3. composer require aws/aws-sdk-php
 *  4. Visit the file in the browser.
 *
 * Security notes (important):
 *  - This is a minimal demo. **Do not** expose this file publicly without adding
 *    authentication (HTTP auth, app login) and HTTPS.
 *  - Production apps should never store long-lived credentials on web roots; use
 *    environment variables, IAM roles, or a backend credentials manager.
 */

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

session_start();

// -------------------------
// Helper: load credentials.ini
// -------------------------
function getCredentials($profile = 'default') {
    $credentialsFile = __DIR__ . '/credentials.ini';

    if (!file_exists($credentialsFile)) {
        http_response_code(500);
        echo json_encode(['error' => "Credentials file not found: $credentialsFile"]);
        exit;
    }

    $credentials = parse_ini_file($credentialsFile, true);
    if (!isset($credentials[$profile])) {
        http_response_code(500);
        echo json_encode(['error' => "Profile '$profile' not found in credentials file"]);
        exit;
    }

    return $credentials[$profile];
}

// -------------------------
// Create S3 client
// -------------------------
function s3Client() {
    static $client = null;
    if ($client) return $client;

    $creds = getCredentials('default');

    $args = [
        'version' => '2006-03-01',
        'region' => $creds['region'] ?? 'us-east-1',
        'credentials' => [
            'key' => $creds['aws_access_key_id'],
            'secret' => $creds['aws_secret_access_key'],
        ],
    ];

    if (!empty($creds['endpoint'])) {
        $args['endpoint'] = $creds['endpoint'];
        $args['use_path_style_endpoint'] = true;
    }

    $client = new S3Client($args);
    return $client;
}

// -------------------------
// Simple router for AJAX
// -------------------------
if (php_sapi_name() !== 'cli' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    try {
        switch ($action) {
            case 'listBuckets':
                $s3 = s3Client();
                $res = $s3->listBuckets();
                echo json_encode(['buckets' => $res['Buckets']]);
                break;

            case 'listObjects':
                // params: bucket, prefix
                $bucket = $_GET['bucket'] ?? null;
                $prefix = isset($_GET['prefix']) ? $_GET['prefix'] : '';
                if (!$bucket) { throw new Exception('bucket required'); }
                $s3 = s3Client();
                $objects = [];
                $params = [
                    'Bucket' => $bucket,
                    'Prefix' => $prefix,
                    'Delimiter' => '/',
                    'MaxKeys' => 1000,
                ];
                $result = $s3->listObjectsV2($params);
                // folders are returned as CommonPrefixes
                $folders = $result['CommonPrefixes'] ?? [];
                $objs = $result['Contents'] ?? [];
                echo json_encode(['folders' => $folders, 'objects' => $objs]);
                break;

            case 'createFolder':
                // bucket, path (e.g. "some/folder/")
                $bucket = $_POST['bucket'] ?? null;
                $path = $_POST['path'] ?? null;
                if (!$bucket || !$path) throw new Exception('bucket and path required');
                if (substr($path, -1) !== '/') $path .= '/';
                $s3 = s3Client();
                $s3->putObject(['Bucket' => $bucket, 'Key' => $path, 'Body' => '']);
                echo json_encode(['ok' => true]);
                break;

            case 'upload':
                // multipart form upload. fields: bucket, path
                $bucket = $_POST['bucket'] ?? null;
                $path = $_POST['path'] ?? '';
                if (!$bucket) throw new Exception('bucket required');
                if (!isset($_FILES['file'])) throw new Exception('file missing');

                $file = $_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('upload error: ' . $file['error']);

                // sanitize filename
                $filename = basename($file['name']);
                $key = rtrim($path, '/') === '' ? $filename : rtrim($path, '/') . '/' . $filename;

                $s3 = s3Client();
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'SourceFile' => $file['tmp_name'],
                    'ACL' => 'private'
                ]);

                echo json_encode(['ok' => true, 'key' => $key]);
                break;

            case 'deleteObject':
                $bucket = $_POST['bucket'] ?? null;
                $key = $_POST['key'] ?? null;
                if (!$bucket || !$key) throw new Exception('bucket and key required');
                $s3 = s3Client();
                $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
                echo json_encode(['ok' => true]);
                break;

            case 'getPresignedUrl':
                $bucket = $_GET['bucket'] ?? null;
                $key = $_GET['key'] ?? null;
                $expires = isset($_GET['expires']) ? intval($_GET['expires']) : 300; // seconds
                if (!$bucket || !$key) throw new Exception('bucket and key required');

                $s3 = s3Client();
                $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
                $request = $s3->createPresignedRequest($cmd, "{$expires} seconds");
                $url = (string) $request->getUri();
                echo json_encode(['url' => $url]);
                break;

            case 'createBucket':
                $bucket = $_POST['bucket'] ?? null;
                if (!$bucket) throw new Exception('bucket required');
                $s3 = s3Client();
                $s3->createBucket(['Bucket' => $bucket]);
                echo json_encode(['ok' => true]);
                break;

            case 'rename':
                // copy from key_old to key_new then delete old
                $bucket = $_POST['bucket'] ?? null;
                $from = $_POST['from'] ?? null;
                $to = $_POST['to'] ?? null;
                if (!$bucket || !$from || !$to) throw new Exception('bucket, from and to required');
                $s3 = s3Client();
                $s3->copyObject(['Bucket' => $bucket, 'CopySource' => urlencode($bucket . '/' . $from), 'Key' => $to]);
                $s3->deleteObject(['Bucket' => $bucket, 'Key' => $from]);
                echo json_encode(['ok' => true]);
                break;

            default:
                throw new Exception('Unknown action');
        }
    } catch (S3Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'aws' => $e->__toString()]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -------------------------
// If not AJAX, render the single-page HTML app
// -------------------------
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>S3 Tiny File Manager</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;max-width:1100px;margin:20px auto;padding:12px}
        header{display:flex;justify-content:space-between;align-items:center}
        .bucket-list, .objects{margin-top:12px}
        .box{border:1px solid #ddd;padding:12px;border-radius:8px;margin-bottom:12px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
        .btn{display:inline-block;padding:6px 10px;border-radius:6px;border:1px solid #888;background:#f7f7f7;cursor:pointer}
        .btn-primary{background:#0b75ef;color:white;border-color:#0b75ef}
        input[type=file]{display:block}
        .breadcrumbs{font-size:14px;margin-bottom:8px}
        .muted{color:#666;font-size:13px}
    </style>
</head>
<body>
<header>
    <h1>S3 Tiny File Manager</h1>
    <div class="muted">Single-file demo — add auth in production</div>
</header>

<div class="box">
    <strong>Buckets</strong>
    <div class="bucket-list" id="bucketList">Loading...</div>
    <div style="margin-top:10px">
        <input id="newBucketName" placeholder="new-bucket-name" />
        <button class="btn" id="createBucketBtn">Create bucket</button>
    </div>
</div>

<div class="box" id="browserBox" style="display:none">
    <div style="display:flex;align-items:center;justify-content:space-between">
        <div>
            <div class="breadcrumbs" id="breadcrumbs"></div>
            <div class="muted" id="currentBucket"></div>
        </div>
        <div>
            <input type="file" id="fileInput" />
            <button class="btn btn-primary" id="uploadBtn">Upload</button>
        </div>
    </div>

    <div style="margin-top:12px">
        <input id="newFolderName" placeholder="New folder name" />
        <button class="btn" id="createFolderBtn">Create Folder</button>
    </div>

    <div class="objects" id="objectsBox">Loading...</div>
</div>

<script>
const apiBase = location.pathname + '?action=';
let currentBucket = null;
let currentPrefix = '';

function api(action, opts={}){
    const url = apiBase + action + (opts.query||'');
    const cfg = opts.cfg || {};
    if (cfg.method === 'POST' || cfg.body instanceof FormData) {
        return fetch(url, {method:'POST', body: cfg.body}).then(r=>r.json());
    }
    return fetch(url).then(r=>r.json());
}

function loadBuckets(){
    api('listBuckets').then(data=>{
        const el = document.getElementById('bucketList');
        if (data.error){ el.innerText = data.error; return; }
        el.innerHTML = '';
        data.buckets.forEach(b=>{
            const d = document.createElement('div');
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.innerText = b.Name;
            btn.onclick = ()=>{ openBucket(b.Name); };
            d.appendChild(btn);
            el.appendChild(d);
        });
    }).catch(err=>{ document.getElementById('bucketList').innerText = err; });
}

function openBucket(name){
    currentBucket = name;
    currentPrefix = '';
    document.getElementById('browserBox').style.display = 'block';
    document.getElementById('currentBucket').innerText = 'Bucket: ' + name;
    renderBreadcrumbs();
    listObjects();
}

function renderBreadcrumbs(){
    const cb = document.getElementById('breadcrumbs');
    const pieces = currentPrefix === '' ? [] : currentPrefix.split('/').filter(Boolean);
    let html = '<button class="btn" onclick="cd(\'\')">/</button>';
    let acc = '';
    pieces.forEach((p,i)=>{
        acc += p + '/';
        html += ' <button class="btn" onclick="cd(\''+acc+'\')">' + p + '</button>';
    });
    cb.innerHTML = html;
}

function cd(prefix){ currentPrefix = prefix; renderBreadcrumbs(); listObjects(); }

function listObjects(){
    if (!currentBucket) return;
    document.getElementById('objectsBox').innerText = 'Loading...';
    api('listObjects', { query: '&bucket=' + encodeURIComponent(currentBucket) + '&prefix=' + encodeURIComponent(currentPrefix) })
    .then(data=>{
        if (data.error) { document.getElementById('objectsBox').innerText = data.error; return; }
        const folders = data.folders || [];
        const objs = data.objects || [];
        const table = document.createElement('table');
        const thead = document.createElement('thead');
        thead.innerHTML = '<tr><th>Name</th><th>Size</th><th>Last Modified</th><th>Actions</th></tr>';
        table.appendChild(thead);
        const tbody = document.createElement('tbody');

        folders.forEach(f=>{
            const name = f.Prefix;
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + name.replace(currentPrefix,'') + '</td><td>—</td><td>—</td><td><button class="btn" onclick="openFolder(\''+name+'\')">Open</button></td>';
            tbody.appendChild(tr);
        });

        objs.forEach(o=>{
            if (o.Key === currentPrefix) return; // skip the folder placeholder
            const tr = document.createElement('tr');
            const shortName = o.Key.replace(currentPrefix,'');
            tr.innerHTML = '<td>' + shortName + '</td><td>' + o.Size + '</td><td>' + o.LastModified + '</td>';
            const actionsTd = document.createElement('td');
            const dl = document.createElement('button'); dl.className='btn'; dl.innerText='Download'; dl.onclick = ()=>downloadObject(o.Key);
            const del = document.createElement('button'); del.className='btn'; del.style.marginLeft='6px'; del.innerText='Delete'; del.onclick = ()=>deleteObject(o.Key);
            const ren = document.createElement('button'); ren.className='btn'; ren.style.marginLeft='6px'; ren.innerText='Rename'; ren.onclick = ()=>renameObjectPrompt(o.Key);
            actionsTd.appendChild(dl); actionsTd.appendChild(del); actionsTd.appendChild(ren);
            tr.appendChild(actionsTd);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        const box = document.getElementById('objectsBox');
        box.innerHTML = '';
        box.appendChild(table);
    });
}

function openFolder(prefix){ currentPrefix = prefix; renderBreadcrumbs(); listObjects(); }

function downloadObject(key){
    api('getPresignedUrl', { query: '&bucket=' + encodeURIComponent(currentBucket) + '&key=' + encodeURIComponent(key) })
    .then(data=>{
        if (data.url) window.open(data.url, '_blank');
    });
}

function deleteObject(key){
    if (!confirm('Delete ' + key + '?')) return;
    const fd = new FormData(); fd.append('bucket', currentBucket); fd.append('key', key);
    fetch(location.pathname + '?action=deleteObject', { method:'POST', body: fd }).then(r=>r.json()).then(()=>listObjects());
}

function renameObjectPrompt(key){
    const newName = prompt('New Key (relative to prefix):', key.replace(currentPrefix,''));
    if (!newName) return;
    const to = currentPrefix + newName;
    const fd = new FormData(); fd.append('bucket', currentBucket); fd.append('from', key); fd.append('to', to);
    fetch(location.pathname + '?action=rename', { method:'POST', body: fd }).then(r=>r.json()).then(()=>listObjects());
}

// upload
document.getElementById('uploadBtn').addEventListener('click', ()=>{
    const fileEl = document.getElementById('fileInput');
    if (!fileEl.files || !fileEl.files.length){ alert('Select file'); return; }
    const file = fileEl.files[0];
    const fd = new FormData();
    fd.append('bucket', currentBucket);
    fd.append('path', currentPrefix);
    fd.append('file', file);
    fetch(location.pathname + '?action=upload', { method:'POST', body: fd }).then(r=>r.json()).then(res=>{ if(res.ok) listObjects(); else alert(JSON.stringify(res)); });
});

// create folder
document.getElementById('createFolderBtn').addEventListener('click', ()=>{
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) return alert('Folder name');
    const path = currentPrefix + name + '/';
    const fd = new FormData(); fd.append('bucket', currentBucket); fd.append('path', path);
    fetch(location.pathname + '?action=createFolder', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ document.getElementById('newFolderName').value=''; listObjects(); });
});

// create bucket
document.getElementById('createBucketBtn').addEventListener('click', ()=>{
    const name = document.getElementById('newBucketName').value.trim();
    if (!name) return alert('bucket name');
    const fd = new FormData(); fd.append('bucket', name);
    fetch(location.pathname + '?action=createBucket', { method:'POST', body: fd }).then(r=>r.json()).then(()=>{ document.getElementById('newBucketName').value=''; loadBuckets(); });
});

// initial load
loadBuckets();
</script>
</body>
</html>
