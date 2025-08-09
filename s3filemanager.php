<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

session_start();

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(16));
}

$actionMsg = '';
$clientError = '';

// Simple username/password auth (edit users as needed)
$auth_users = [
    'admin' => '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', // admin@123
];

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && isset($_POST['token']) && hash_equals($_SESSION['token'], $_POST['token'])) {
    if ($_POST['type'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($username !== '' && isset($auth_users[$username]) && password_verify($password, $auth_users[$username])) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $username;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            $loginError = 'Invalid username or password.';
        }
    } elseif ($_POST['type'] === 'logout') {
        unset($_SESSION['logged_in'], $_SESSION['username'], $_SESSION['s3']);
        $actionMsg = 'You have been logged out.';
    }
}

if (empty($_SESSION['logged_in'])) {
    $tokenVal = htmlspecialchars($_SESSION['token'], ENT_QUOTES);
    $loginMsgHtml = $actionMsg ? '<div class="message">' . htmlspecialchars($actionMsg) . '</div>' : '';
    $loginErrHtml = $loginError ? '<div class="mt-4 p-3 bg-red-50 border-l-4 border-red-600 text-sm">' . htmlspecialchars($loginError) . '</div>' : '';
    echo '<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Login - S3 File Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
  <div class="min-h-screen flex items-center justify-center">
    <div class="bg-white rounded shadow p-6 w-full max-w-sm">
      <h1 class="text-xl font-semibold mb-4">Sign in</h1>'
      . $loginMsgHtml . $loginErrHtml .
      '<form method="post" class="space-y-3">
        <input type="hidden" name="token" value="' . $tokenVal . '">
        <input type="hidden" name="type" value="login">
        <div>
          <label class="block text-sm font-medium">Username</label>
          <input name="username" type="text" class="mt-1 w-full border rounded p-2" required>
        </div>
        <div>
          <label class="block text-sm font-medium">Password</label>
          <input name="password" type="password" class="mt-1 w-full border rounded p-2" required>
        </div>
        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded" type="submit">Login</button>
      </form>
    </div>
  </div>
</body>
</html>';
    exit;
}

function s3_delete_prefix(S3Client $s3, string $bucket, string $prefix): void
{
    $token = null;
    do {
        $args = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
        if ($token) { $args['ContinuationToken'] = $token; }
        $res = $s3->listObjectsV2($args);
        $keys = [];
        foreach (($res['Contents'] ?? []) as $obj) {
            $keys[] = ['Key' => $obj['Key']];
        }
        if ($keys) {
            $s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $keys, 'Quiet' => true]]);
        }
        $token = $res['IsTruncated'] ? ($res['NextContinuationToken'] ?? null) : null;
    } while ($token);
}

function s3_rename_prefix(S3Client $s3, string $bucket, string $oldPrefix, string $newPrefix): void
{
    $token = null;
    do {
        $args = ['Bucket' => $bucket, 'Prefix' => $oldPrefix, 'MaxKeys' => 1000];
        if ($token) { $args['ContinuationToken'] = $token; }
        $res = $s3->listObjectsV2($args);
        foreach (($res['Contents'] ?? []) as $obj) {
            $key = $obj['Key'];
            $suffix = substr($key, strlen($oldPrefix));
            $target = rtrim($newPrefix, '/') . '/' . ltrim($suffix, '/');
            $s3->copyObject([
                'Bucket' => $bucket,
                'CopySource' => rawurlencode($bucket . '/' . $key),
                'Key' => $target,
            ]);
        }
        $token = $res['IsTruncated'] ? ($res['NextContinuationToken'] ?? null) : null;
    } while ($token);
    // delete old
    s3_delete_prefix($s3, $bucket, $oldPrefix);
}

// Credentials form handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && isset($_POST['token']) && hash_equals($_SESSION['token'], $_POST['token'])) {
    if ($_POST['type'] === 'creds') {
        $_SESSION['s3'] = [
            'key' => trim($_POST['aws_access_key_id'] ?? ''),
            'secret' => trim($_POST['aws_secret_access_key'] ?? ''),
            'region' => trim($_POST['region'] ?? ''),
            'endpoint' => trim($_POST['endpoint'] ?? null),
            'path_style' => isset($_POST['use_path_style_endpoint']) ? true : false,
        ];
        $actionMsg = 'S3 credentials saved.';
    } elseif ($_POST['type'] === 'clear-creds') {
        unset($_SESSION['s3']);
        $actionMsg = 'S3 credentials cleared.';
}
}

// Build S3 client from session creds
$s3 = null;
if (!empty($_SESSION['s3']['key']) && !empty($_SESSION['s3']['secret']) && !empty($_SESSION['s3']['region'])) {
    try {
        $cfg = [
        'credentials' => [
                'key' => $_SESSION['s3']['key'],
                'secret' => $_SESSION['s3']['secret'],
        ],
            'region' => $_SESSION['s3']['region'],
        'version' => '2006-03-01',
        ];
        if (!empty($_SESSION['s3']['endpoint'])) {
            $cfg['endpoint'] = $_SESSION['s3']['endpoint'];
            $host = parse_url($_SESSION['s3']['endpoint'], PHP_URL_HOST) ?: '';
            if (preg_match('/\b(hel1|fsn1|nbg1)\b/i', $host, $m)) {
                $cfg['region'] = strtolower($m[1]);
            }
        }
        if (isset($_SESSION['s3']['path_style'])) {
            $cfg['use_path_style_endpoint'] = (bool) $_SESSION['s3']['path_style'];
        }
        $s3 = new S3Client($cfg);
    } catch (Exception $e) {
        $clientError = $e->getMessage();
    }
}

// File view/download proxy (simple passthrough)
if ($s3 && isset($_GET['download']) && isset($_GET['bucket']) && isset($_GET['key'])) {
    $bucket = $_GET['bucket'];
    $key = $_GET['key'];
    try {
        $res = $s3->getObject(['Bucket'=>$bucket, 'Key'=>$key]);
        foreach (['ContentType','ContentLength','Last-Modified','ETag'] as $h) {
            if (isset($res['@metadata']['headers'][strtolower($h)])) {
                header($h . ': ' . $res['@metadata']['headers'][strtolower($h)]);
            }
        }
        echo $res['Body'];
    } catch (Exception $e) {
        http_response_code(404);
        echo 'Not found';
    }
    exit;
}

// Tree JSON endpoint
if (isset($_GET['tree']) && $s3) {
    header('Content-Type: application/json');
    $bucket = $_GET['bucket'] ?? '';
    $prefix = $_GET['prefix'] ?? '';
    $prefix = trim($prefix);
    if ($prefix !== '' && substr($prefix, -1) !== '/') { $prefix .= '/'; }
    if ($bucket === '') {
        echo json_encode(['folders' => [], 'files' => []]);
        exit;
    }
    $params = ['Bucket' => $bucket, 'Delimiter' => '/'];
    if ($prefix !== '') { $params['Prefix'] = $prefix; }
    try {
        $result = $s3->listObjectsV2($params);
        $folders = [];
        if (!empty($result['CommonPrefixes'])) {
            foreach ($result['CommonPrefixes'] as $cp) {
                $folders[] = $cp['Prefix'];
            }
        }
        $files = [];
        if (!empty($result['Contents'])) {
            foreach ($result['Contents'] as $obj) {
                $key = $obj['Key'];
                if ($key === $prefix || substr($key, -1) === '/') { continue; }
                $files[] = $key;
            }
        }
        echo json_encode(['folders' => $folders, 'files' => $files]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle actions (delete, create, rename, etc.)
$actionMsg = $actionMsg; // keep message from creds flow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && hash_equals($_SESSION['token'], $_POST['token'])) {
    $action = $_POST['action'] ?? '';
    $bucket = $_POST['bucket'] ?? '';
    $key = $_POST['key'] ?? '';

    try {
        if (isset($_POST['type']) && $_POST['type'] === 'bucket-action' && isset($_POST['s3_action'])) {
            if ($_POST['s3_action'] === 'create-bucket' && !empty($bucket)) {
                $params = ['Bucket' => $bucket];
                // If region differs from client region for providers like Hetzner, pass LocationConstraint
                if (!empty($_SESSION['s3']['region'])) {
                    $params['CreateBucketConfiguration'] = ['LocationConstraint' => $_SESSION['s3']['region']];
                }
                $s3->createBucket($params);
                $actionMsg = "Bucket created: $bucket";
            } elseif ($_POST['s3_action'] === 'delete-bucket' && !empty($bucket)) {
                $s3->deleteBucket(['Bucket' => $bucket]);
                $actionMsg = "Bucket deleted: $bucket";
            }
        } elseif ($action === 'delete-file') {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $actionMsg = "File deleted: $key";
        } elseif ($action === 'create-folder') {
            $prefix = isset($_POST['prefix']) ? rtrim($_POST['prefix'], '/') : '';
            if ($prefix !== '') { $prefix .= '/'; }
            $folderName = $prefix . trim($_POST['folder_name'], '/') . '/';
            $s3->putObject(['Bucket' => $bucket, 'Key' => $folderName, 'Body' => '']);
            $actionMsg = "Folder created: $folderName";
        } elseif ($action === 'rename-object') {
            $newName = $_POST['new_name'];
            $s3->copyObject([
                'Bucket' => $bucket,
                'CopySource' => rawurlencode($bucket . '/' . $key),
                'Key' => $newName
            ]);
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $actionMsg = "Renamed to: $newName";
        } elseif ($action === 'upload-object') {
            $prefix = isset($_POST['prefix']) ? rtrim($_POST['prefix'], '/') . '/' : '';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $name = basename($_FILES['file']['name']);
                $putKey = $prefix . $name;
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $putKey,
                    'SourceFile' => $_FILES['file']['tmp_name']
                ]);
                $actionMsg = "Uploaded: $putKey";
            } else {
                $actionMsg = 'Upload failed.';
            }
        }
    } catch (AwsException $e) {
        $actionMsg = "Error: " . $e->getAwsErrorMessage();
    } catch (Exception $e) {
        $actionMsg = "Error: " . $e->getMessage();
    }
}

// List buckets
$buckets = $s3 ? $s3->listBuckets() : ['Buckets' => []];
$currentBucket = $_GET['bucket'] ?? '';
$objects = [];
if ($currentBucket && $s3) {
    $objects = $s3->listObjectsV2(['Bucket' => $currentBucket, 'Delimiter' => '/']);
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>AWS S3 File Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        header { background: #232f3e; color: white; padding: 10px; }
        .icon { margin-right: 6px; }
        .message { padding: 10px; margin: 10px 0; background: #e1f5fe; border-left: 4px solid #0288d1; }
        .tree ul{ margin-left: 1rem; }
        .tree li{ list-style: none; }
    </style>
</head>
<body>
<header>
    <div class="max-w-7xl mx-auto flex justify-between items-center">
        <h1><i class="fa-brands fa-aws"></i> AWS S3 File Manager</h1>
        <div class="flex items-center gap-3">
            <span class="text-sm">Signed in as <?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
                <input type="hidden" name="type" value="logout">
                <button class="border border-red-500 text-red-600 px-3 py-1 rounded text-sm" type="submit"><i class="fa fa-right-from-bracket"></i> Logout</button>
            </form>
        </div>
    </div>
</header>
<div class="max-w-7xl mx-auto p-4">
    <?php if ($actionMsg): ?>
        <div class="message"><?= htmlspecialchars($actionMsg) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded shadow p-4 mb-4">
        <form method="post" class="grid md:grid-cols-6 gap-4 items-end">
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
            <input type="hidden" name="type" value="creds">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium">Access Key ID</label>
                <input type="text" name="aws_access_key_id" class="mt-1 w-full border rounded p-2" value="<?= htmlspecialchars($_SESSION['s3']['key'] ?? '') ?>" required>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium">Secret Access Key</label>
                <input type="text" name="aws_secret_access_key" class="mt-1 w-full border rounded p-2" value="<?= htmlspecialchars($_SESSION['s3']['secret'] ?? '') ?>" required>
            </div>
            <div>
                <label class="block text-sm font-medium">Region</label>
                <input type="text" name="region" class="mt-1 w-full border rounded p-2" value="<?= htmlspecialchars($_SESSION['s3']['region'] ?? '') ?>" required>
            </div>
            <div>
                <label class="block text-sm font-medium">Endpoint (optional)</label>
                <input type="text" name="endpoint" class="mt-1 w-full border rounded p-2" placeholder="https://s3.amazonaws.com" value="<?= htmlspecialchars($_SESSION['s3']['endpoint'] ?? '') ?>">
            </div>
            <div class="md:col-span-6 flex items-center gap-4">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="use_path_style_endpoint" class="mr-2" <?= !empty($_SESSION['s3']['path_style']) ? 'checked' : '' ?>> Path style
                </label>
                <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded" type="submit"><i class="fa fa-save"></i> Save</button>
                <?php if (!empty($_SESSION['s3'])): ?>
                <button class="border border-red-500 text-red-600 px-4 py-2 rounded" type="submit" formaction="" name="type" value="clear-creds"><i class="fa fa-unlock"></i> Clear</button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($clientError): ?>
            <div class="mt-4 p-3 bg-red-50 border-l-4 border-red-600 text-sm">Client error: <?= htmlspecialchars($clientError) ?></div>
        <?php endif; ?>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-white rounded shadow p-3">
            <div class="flex justify-between items-center mb-2">
                <h2 class="font-semibold"><i class="fa fa-database"></i> Buckets</h2>
                <?php if ($s3): ?>
                <form class="flex items-center gap-2" method="post">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
                    <input type="hidden" name="type" value="bucket-action">
                    <input type="hidden" name="s3_action" value="create-bucket">
                    <input type="text" name="bucket" class="border rounded p-1 text-sm" placeholder="New bucket" required>
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-sm" type="submit"><i class="fa fa-plus"></i></button>
                </form>
                <?php endif; ?>
            </div>
            <ul class="divide-y">
                <?php foreach ($buckets['Buckets'] as $bucket): ?>
                    <li class="py-2 flex justify-between items-center">
                        <a class="text-blue-700" href="?bucket=<?= urlencode($bucket['Name']) ?>"><i class="fa fa-database icon"></i> <?= htmlspecialchars($bucket['Name']) ?></a>
                        <form class="inline" method="post" onsubmit="return confirm('Delete bucket?')">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
                            <input type="hidden" name="type" value="bucket-action">
                            <input type="hidden" name="s3_action" value="delete-bucket">
                    <input type="hidden" name="bucket" value="<?= htmlspecialchars($bucket['Name']) ?>">
                            <button type="submit" class="text-red-600"><i class="fa fa-trash"></i></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
        </div>
        <div class="md:col-span-2 bg-white rounded shadow p-3">
            <div class="flex justify-between items-center mb-2">
                <h2 class="font-semibold"><i class="fa fa-sitemap"></i> <?= $currentBucket ? 'Bucket: ' . htmlspecialchars($currentBucket) : 'Select a bucket' ?></h2>
    <?php if ($currentBucket): ?>
                <div class="flex items-center gap-2">
                    <form class="inline" method="post">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
            <input type="hidden" name="action" value="create-folder">
                        <input type="hidden" id="createPrefix" name="prefix" value="">
                        <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
                        <input type="text" name="folder_name" class="border rounded p-1 text-sm" placeholder="New folder" required>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-sm"><i class="fa fa-folder-plus"></i></button>
                    </form>
                    <form class="inline" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
                        <input type="hidden" name="action" value="upload-object">
                        <input type="hidden" id="uploadPrefix" name="prefix" value="">
            <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
                        <input type="file" name="file" class="text-sm">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-sm"><i class="fa fa-upload"></i></button>
        </form>
                </div>
                            <?php endif; ?>
            </div>
            <div class="tree" id="tree"></div>
<?php if ($currentBucket): ?>
<div class="mt-3">
    <form class="inline" method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['token']) ?>">
        <input type="hidden" name="action" value="delete-file">
        <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
        <input id="selectedKey" type="hidden" name="key" value="">
        <button type="submit" class="border border-red-500 text-red-600 px-2 py-1 rounded text-sm"><i class="fa fa-trash"></i> Delete Selected</button>
    </form>
</div>
<?php endif; ?>
        </div>
</div>
<script>
// Simple lazy tree using the ?tree=1 endpoint
const treeEl = document.getElementById('tree');
const currentBucket = <?= json_encode($currentBucket) ?>;
function renderNode(name, fullPrefix, isFolder) {
  const li = document.createElement('li');
  const label = document.createElement('span');
  label.className = 'cursor-pointer';
  if (isFolder) {
    label.innerHTML = '<i class="fa fa-folder mr-1"></i>' + name;
  } else {
    const viewUrl = '?download=1&bucket=' + encodeURIComponent(currentBucket) + '&key=' + encodeURIComponent(fullPrefix);
    label.innerHTML = '<i class="fa fa-file mr-1"></i>' + name +
      ' <a class="ml-2" title="View" target="_blank" href="' + viewUrl + '"><i class="fa fa-eye"></i></a>' +
      ' <button type="button" class="text-red-600 ml-2" title="Delete" onclick="selectForDelete(\'' + fullPrefix.replace(/'/g, "\\'") + '\')"><i class="fa fa-trash"></i></button>';
  }
  li.appendChild(label);
  if (isFolder) {
    const ul = document.createElement('ul');
    ul.className = 'ml-4 hidden';
    label.addEventListener('click', async () => {
      if (ul.childElementCount === 0) {
        const res = await fetch(`?tree=1&bucket=${encodeURIComponent(currentBucket)}&prefix=${encodeURIComponent(fullPrefix)}`);
        const data = await res.json();
        (data.folders || []).forEach(p => {
          const fname = p.split('/').filter(Boolean).pop() + '/';
          ul.appendChild(renderNode(fname, p, true));
        });
        (data.files || []).forEach(f => {
          const fname = f.split('/').pop();
          ul.appendChild(renderNode(fname, f, false));
        });
      }
      ul.classList.toggle('hidden');
      setCurrentPrefix(fullPrefix);
    });
    li.appendChild(ul);
  }
  return li;
}
function selectForDelete(key){
  const input = document.getElementById('selectedKey');
  if (input){ input.value = key; }
}
async function loadRoot() {
  if (!currentBucket) { treeEl.textContent = 'Select a bucket'; return; }
  treeEl.innerHTML = '';
  try {
    const res = await fetch(`?tree=1&bucket=${encodeURIComponent(currentBucket)}`);
    const data = await res.json();
    const ul = document.createElement('ul');
    (data.folders || []).forEach(p => {
      const fname = p.split('/').filter(Boolean).pop() + '/';
      ul.appendChild(renderNode(fname, p, true));
    });
    (data.files || []).forEach(f => {
      const fname = f.split('/').pop();
      ul.appendChild(renderNode(fname, f, false));
    });
    treeEl.appendChild(ul);
  } catch (e) {
    treeEl.textContent = 'Failed to load tree';
  }
  // default prefix root
  const cp = document.getElementById('createPrefix'); if (cp) cp.value = '';
  const up = document.getElementById('uploadPrefix'); if (up) up.value = '';
}
// store current prefix when user expands a folder (set on click)
function setCurrentPrefix(prefix){
  const cp = document.getElementById('createPrefix'); if (cp) cp.value = prefix;
  const up = document.getElementById('uploadPrefix'); if (up) up.value = prefix;
}
// Hook: when a folder label is clicked and expanded, set prefix
// We piggyback inside renderNode where folders are toggled

// init
loadRoot();
</script>
</body>
</html>
