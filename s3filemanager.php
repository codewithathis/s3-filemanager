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

// Presigned URL redirect endpoint
if ($s3 && isset($_GET['presign']) && isset($_GET['bucket']) && isset($_GET['key'])) {
    $bucket = $_GET['bucket'];
    $key = $_GET['key'];
    try {
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
        $req = $s3->createPresignedRequest($cmd, '+5 minutes');
        header('Location: ' . (string)$req->getUri(), true, 302);
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Failed to generate URL';
    }
    exit;
}

// Bucket size endpoint (sums Size across all objects)
if ($s3 && isset($_GET['bucket_size']) && isset($_GET['bucket'])) {
    header('Content-Type: application/json');
    $bucket = $_GET['bucket'];
    $token = null;
    $totalBytes = 0;
    $objectsCount = 0;
    try {
        do {
            $args = ['Bucket' => $bucket, 'MaxKeys' => 1000];
            if ($token) { $args['ContinuationToken'] = $token; }
            $res = $s3->listObjectsV2($args);
            foreach (($res['Contents'] ?? []) as $obj) {
                if (substr($obj['Key'], -1) === '/') { continue; }
                $totalBytes += (int)($obj['Size'] ?? 0);
                $objectsCount++;
            }
            $token = !empty($res['IsTruncated']) ? ($res['NextContinuationToken'] ?? null) : null;
        } while ($token);
        echo json_encode(['bytes' => $totalBytes, 'objects' => $objectsCount]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
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
                $files[] = [
                    'key' => $key,
                    'size' => (int)($obj['Size'] ?? 0),
                    'last_modified' => isset($obj['LastModified']) ? (is_string($obj['LastModified']) ? $obj['LastModified'] : ($obj['LastModified']->format('c') ?? '')) : null
                ];
            }
        }
        echo json_encode(['folders' => $folders, 'files' => $files]);
    } catch (Exception $e) {
        // If bucket doesn't exist, return empty result instead of error
        if (strpos($e->getMessage(), 'NoSuchBucket') !== false) {
            echo json_encode(['folders' => [], 'files' => [], 'error' => 'Bucket does not exist']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
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
                // Redirect to home after bucket deletion to avoid errors
                header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode("Bucket deleted: $bucket"));
                exit;
            }
        } elseif ($action === 'delete-file') {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $actionMsg = "File deleted: $key";
        } elseif ($action === 'delete-folder') {
            $path = $_POST['path'] ?? '';
            if ($path) {
                try {
                    // Delete all objects in the folder
                    s3_delete_prefix($s3, $bucket, $path);
                    $actionMsg = "Folder deleted: $path";
                } catch (Exception $e) {
                    $actionMsg = "Error deleting folder: " . $e->getMessage();
                }
            } else {
                $actionMsg = 'No folder path specified.';
            }
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
        } elseif ($action === 'bulk-delete') {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (is_array($items) && !empty($items)) {
                $deletedCount = 0;
                $errors = [];
                
                foreach ($items as $item) {
                    try {
                        if ($item['type'] === 'file') {
                            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $item['key']]);
                            $deletedCount++;
                        } elseif ($item['type'] === 'folder') {
                            // Delete all objects in the folder
                            s3_delete_prefix($s3, $bucket, $item['path']);
                            $deletedCount++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to delete {$item['name']}: " . $e->getMessage();
                    }
                }
                
                if ($deletedCount > 0) {
                    $actionMsg = "Successfully deleted $deletedCount item(s)";
                    if (!empty($errors)) {
                        $actionMsg .= ". Errors: " . implode('; ', $errors);
                    }
                } else {
                    $actionMsg = "No items were deleted. Errors: " . implode('; ', $errors);
                }
            } else {
                $actionMsg = 'No items selected for deletion.';
            }
        } elseif ($action === 'bulk-rename') {
            $items = json_decode($_POST['items'] ?? '[]', true);
            $prefix = $_POST['prefix'] ?? '';
            $suffix = $_POST['suffix'] ?? '';
            
            if (is_array($items) && !empty($items) && ($prefix !== '' || $suffix !== '')) {
                $renamedCount = 0;
                $errors = [];
                
                foreach ($items as $item) {
                    try {
                        if ($item['type'] === 'file') {
                            $oldKey = $item['key'];
                            $newKey = $prefix . $item['name'] . $suffix;
                            
                            // Copy to new location
                            $s3->copyObject([
                                'Bucket' => $bucket,
                                'CopySource' => rawurlencode($bucket . '/' . $oldKey),
                                'Key' => $newKey,
                            ]);
                            // Delete old file
                            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $oldKey]);
                            $renamedCount++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to rename {$item['name']}: " . $e->getMessage();
                    }
                }
                
                if ($renamedCount > 0) {
                    $actionMsg = "Successfully renamed $renamedCount item(s)";
                    if (!empty($errors)) {
                        $actionMsg .= ". Errors: " . implode('; ', $errors);
                    }
                } else {
                    $actionMsg = "No items were renamed. Errors: " . implode('; ', $errors);
                }
            } else {
                $actionMsg = 'No items selected or no changes specified.';
            }
        } elseif ($action === 'bulk-move') {
            $items = json_decode($_POST['items'] ?? '[]', true);
            $targetFolder = $_POST['target_folder'] ?? '';
            
            if (is_array($items) && !empty($items)) {
                $movedCount = 0;
                $errors = [];
                
                foreach ($items as $item) {
                    try {
                        if ($item['type'] === 'file') {
                            $oldKey = $item['key'];
                            $fileName = basename($oldKey);
                            $newKey = $targetFolder . $fileName;
                            
                            // Copy to new location
                            $s3->copyObject([
                                'Bucket' => $bucket,
                                'CopySource' => rawurlencode($bucket . '/' . $oldKey),
                                'Key' => $newKey,
                            ]);
                            // Delete old file
                            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $oldKey]);
                            $movedCount++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to move {$item['name']}: " . $e->getMessage();
                    }
                }
                
                if ($movedCount > 0) {
                    $actionMsg = "Successfully moved $movedCount item(s) to " . ($targetFolder ?: 'root');
                    if (!empty($errors)) {
                        $actionMsg .= ". Errors: " . implode('; ', $errors);
                    }
                } else {
                    $actionMsg = "No items were moved. Errors: " . implode('; ', $errors);
                }
            } else {
                $actionMsg = 'No items selected for moving.';
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

// Check if current bucket exists and handle errors
if ($currentBucket && $s3) {
    try {
        $objects = $s3->listObjectsV2(['Bucket' => $currentBucket, 'Delimiter' => '/']);
    } catch (Exception $e) {
        // If bucket doesn't exist, redirect to home
        if (strpos($e->getMessage(), 'NoSuchBucket') !== false) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode('The selected bucket no longer exists.'));
            exit;
        }
        // For other errors, show the error message
        $actionMsg = "Error accessing bucket: " . $e->getMessage();
        $currentBucket = ''; // Reset current bucket
        $objects = [];
    }
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
        .folder-card { transition: all 0.2s ease; }
        .folder-card:hover { transform: translateY(-2px); }
        .file-card { transition: all 0.2s ease; }
        .file-card:hover { transform: translateY(-2px); }
        .menu-item { padding: 8px 16px; cursor: pointer; transition: background-color 0.2s; }
        .menu-item:hover { background-color: #f3f4f6; }
        .menu-item:first-child { border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .menu-item:last-child { border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; }
        
        /* Multi-select styling */
        .file-checkbox, .folder-checkbox {
            transform: scale(1.2);
            cursor: pointer;
        }
        
        .file-card.selected, .folder-card.selected {
            border: 2px solid #3b82f6;
            background-color: #eff6ff;
        }
        
        .file-card:hover, .folder-card:hover {
            border-color: #3b82f6;
        }
        
        #bulkActions {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<header>
    <div class="max-w-7xl mx-auto flex justify-between items-center">
        <h1><i class="fa-brands fa-aws"></i> S3 File Manager</h1>
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
    
    <?php if (isset($_GET['msg'])): ?>
        <div class="message"><?= htmlspecialchars($_GET['msg']) ?></div>
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
                        <a class="text-blue-700 flex items-center gap-2" href="?bucket=<?= urlencode($bucket['Name']) ?>"><i class="fa fa-database icon"></i> <span><?= htmlspecialchars($bucket['Name']) ?></span> <span id="size-<?= htmlspecialchars($bucket['Name']) ?>" class="text-xs text-gray-600"></span></a>
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
            <div class="mb-3 flex gap-2">
                <button id="treeViewBtn" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" onclick="switchView('tree')">Tree View</button>
                <button id="folderViewBtn" class="px-3 py-1 rounded text-sm border border-gray-300" onclick="switchView('folder')">Folder View</button>
            </div>
            
            <div id="treeView" class="tree"></div>
            <div id="folderView" class="hidden">
                <div id="folderGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4"></div>
            </div>
            
<?php if ($currentBucket): ?>
<div class="mt-3 flex items-center gap-3">
    <div class="flex items-center gap-2">
        <input type="checkbox" id="selectAll" class="rounded">
        <label for="selectAll" class="text-sm text-gray-700">Select All</label>
        <span class="text-xs text-gray-500">(Ctrl+A)</span>
    </div>
    <div id="bulkActions" class="hidden flex gap-2">
        <div class="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded px-3 py-1">
            <span class="text-sm text-blue-700 font-medium">
                <i class="fa fa-check-circle mr-1"></i>
                <span id="selectedCount">0</span> item(s) selected
            </span>
        </div>
        <button onclick="deleteSelected()" class="border border-red-500 text-red-600 px-3 py-1 rounded text-sm hover:bg-red-50" title="Delete selected items (Delete key)">
            <i class="fa fa-trash"></i> Delete
        </button>
        <button onclick="downloadSelected()" class="border border-blue-500 text-blue-600 px-3 py-1 rounded text-sm hover:bg-blue-50" title="Download selected files">
            <i class="fa fa-download"></i> Download
        </button>
        <button onclick="copySelectedUrls()" class="border border-green-500 text-green-600 px-3 py-1 rounded text-sm hover:bg-green-50" title="Copy URLs to clipboard">
            <i class="fa fa-link"></i> Copy URLs
        </button>
        <button onclick="bulkRename()" class="border border-purple-500 text-purple-600 px-3 py-1 rounded text-sm hover:bg-purple-50" title="Add prefix/suffix to selected items">
            <i class="fa fa-edit"></i> Bulk Rename
        </button>
        <button onclick="bulkMove()" class="border border-orange-500 text-orange-600 px-3 py-1 rounded text-sm hover:bg-orange-50" title="Move selected items to another folder">
            <i class="fa fa-arrows-up-down-left-right"></i> Move
        </button>
        <button onclick="clearSelection()" class="border border-gray-500 text-gray-600 px-3 py-1 rounded text-sm hover:bg-gray-50" title="Clear all selections (Esc key)">
            <i class="fa fa-times"></i> Clear
        </button>
    </div>
</div>
<?php endif; ?>
        </div>
</div>

<!-- Context Menu -->
<div id="contextMenu" class="fixed hidden bg-white rounded-lg shadow-lg border border-gray-200 z-50 min-w-48">
  <div class="menu-items py-1"></div>
</div>

<script>
// Simple lazy tree using the ?tree=1 endpoint
const treeEl = document.getElementById('treeView');
const folderEl = document.getElementById('folderView');
const folderGridEl = document.getElementById('folderGrid');
const currentBucket = <?= json_encode($currentBucket) ?>;

function formatBytes(bytes){
  if (!Number.isFinite(bytes) || bytes < 0) return '';
  const units = ['B','KB','MB','GB','TB'];
  let i = 0; let v = bytes;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
}

function formatDate(iso){
  try { return new Date(iso).toLocaleString(); } catch { return ''; }
}

function getFileIcon(filename) {
  const ext = filename.split('.').pop().toLowerCase();
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
  const videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];
  const audioExts = ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma'];
  const docExts = ['pdf', 'doc', 'docx', 'txt', 'rtf'];
  
  if (imageExts.includes(ext)) return 'fa-image';
  if (videoExts.includes(ext)) return 'fa-video';
  if (audioExts.includes(ext)) return 'fa-music';
  if (docExts.includes(ext)) return 'fa-file-lines';
  return 'fa-file';
}

function switchView(view) {
  const treeBtn = document.getElementById('treeViewBtn');
  const folderBtn = document.getElementById('folderViewBtn');
  
  if (view === 'tree') {
    treeEl.classList.remove('hidden');
    folderEl.classList.add('hidden');
    treeBtn.className = 'px-3 py-1 rounded text-sm bg-blue-600 text-white';
    folderBtn.className = 'px-3 py-1 rounded text-sm border border-gray-300';
    localStorage.setItem('s3filemanager_view', 'tree');
  } else {
    treeEl.classList.add('hidden');
    folderEl.classList.remove('hidden');
    folderBtn.className = 'px-3 py-1 rounded text-sm bg-blue-600 text-white';
    treeBtn.className = 'px-3 py-1 rounded text-sm border border-gray-300';
    localStorage.setItem('s3filemanager_view', 'folder');
  }
}
function renderNode(nameOrLabel, fullPrefix, isFolder) {
  const li = document.createElement('li');
  const label = document.createElement('span');
  label.className = 'cursor-pointer';
  if (isFolder) {
    label.innerHTML = '<i class="fa fa-folder mr-1"></i>' + nameOrLabel;
  } else {
    const presignUrl = '?presign=1&bucket=' + encodeURIComponent(currentBucket) + '&key=' + encodeURIComponent(fullPrefix);
    label.innerHTML = '<i class="fa fa-file mr-1"></i>' + nameOrLabel +
      ' <a class="ml-2" title="Open (signed URL)" target="_blank" href="' + presignUrl + '"><i class="fa fa-eye"></i></a>' +
      ' <button type="button" class="text-red-600 ml-2" title="Delete" onclick="selectForDelete(\'' + fullPrefix.replace(/'/g, "\\'") + '\')"><i class="fa fa-trash"></i></button>';
  }
  li.appendChild(label);
  if (isFolder) {
    const ul = document.createElement('ul');
    ul.className = 'ml-4 hidden';
    label.addEventListener('click', async () => {
      if (ul.childElementCount === 0) {
        try {
          const res = await fetch(`?tree=1&bucket=${encodeURIComponent(currentBucket)}&prefix=${encodeURIComponent(fullPrefix)}`);
          const data = await res.json();
          
          // Check if bucket doesn't exist
          if (data.error && data.error.includes('Bucket does not exist')) {
            // Redirect to home if bucket doesn't exist
            window.location.href = window.location.pathname + '?msg=' + encodeURIComponent('The selected bucket no longer exists.');
            return;
          }
          
          (data.folders || []).forEach(p => {
            const fname = p.split('/').filter(Boolean).pop() + '/';
            ul.appendChild(renderNode(fname, p, true));
          });
          (data.files || []).forEach(f => {
            const key = typeof f === 'string' ? f : f.key;
            const size = typeof f === 'object' ? f.size : null;
            const lastMod = typeof f === 'object' ? f.last_modified : null;
            const fname = key.split('/').pop();
            const labelText = fname + (size != null ? ` (${formatBytes(size)})` : '') + (lastMod ? ` • ${formatDate(lastMod)}` : '');
            ul.appendChild(renderNode(labelText, key, false));
          });
        } catch (error) {
          console.error('Error loading folder contents:', error);
        }
      }
      ul.classList.toggle('hidden');
      setCurrentPrefix(fullPrefix);
    });
    li.appendChild(ul);
  }
  return li;
}
function selectForDelete(key){
  if (confirm(`Are you sure you want to delete "${key.split('/').pop()}"?`)) {
    // Create a form to submit deletion
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="token" value="${document.querySelector('input[name="token"]').value}">
      <input type="hidden" name="action" value="delete-file">
      <input type="hidden" name="bucket" value="${currentBucket}">
      <input type="hidden" name="key" value="${key}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}
async function loadRoot() {
  if (!currentBucket) { 
    treeEl.textContent = 'Select a bucket'; 
    folderGridEl.innerHTML = '';
    return; 
  }
  
  const currentPrefix = new URLSearchParams(window.location.search).get('prefix') || '';
  
  try {
    const res = await fetch(`?tree=1&bucket=${encodeURIComponent(currentBucket)}&prefix=${encodeURIComponent(currentPrefix)}`);
    const data = await res.json();
    
    // Check if bucket doesn't exist
    if (data.error && data.error.includes('Bucket does not exist')) {
      // Redirect to home if bucket doesn't exist
      window.location.href = window.location.pathname + '?msg=' + encodeURIComponent('The selected bucket no longer exists.');
      return;
    }
    
    // Load tree view
    treeEl.innerHTML = '';
    const ul = document.createElement('ul');
    
    // Add breadcrumb navigation
    if (currentPrefix) {
      const breadcrumb = document.createElement('li');
      breadcrumb.innerHTML = `<a href="?bucket=${encodeURIComponent(currentBucket)}" class="text-blue-600 hover:text-blue-800"><i class="fa fa-level-up-alt"></i> .. (back to root)</a>`;
      ul.appendChild(breadcrumb);
    }
    
    (data.folders || []).forEach(p => {
      const fname = p.split('/').filter(Boolean).pop() + '/';
      ul.appendChild(renderNode(fname, p, true));
    });
    (data.files || []).forEach(f => {
      const key = typeof f === 'string' ? f : f.key;
      const size = typeof f === 'object' ? f.size : null;
      const lastMod = typeof f === 'object' ? f.last_modified : null;
      const fname = key.split('/').pop();
      const labelText = fname + (size != null ? ` (${formatBytes(size)})` : '') + (lastMod ? ` • ${formatDate(lastMod)}` : '');
      ul.appendChild(renderNode(labelText, key, false));
    });
    treeEl.appendChild(ul);
    
    // Load folder view
    loadFolderView(data, currentPrefix);
    
  } catch (e) {
    treeEl.textContent = 'Failed to load tree';
    folderGridEl.innerHTML = '<div class="col-span-full text-center text-gray-500">Failed to load files</div>';
  }
  
  // Set current prefix for forms
  const cp = document.getElementById('createPrefix'); if (cp) cp.value = currentPrefix;
  const up = document.getElementById('uploadPrefix'); if (up) up.value = currentPrefix;
}

function loadFolderView(data, currentPrefix) {
  folderGridEl.innerHTML = '';
  
  // Add breadcrumb navigation
  if (currentPrefix) {
    const breadcrumbCard = document.createElement('div');
    breadcrumbCard.className = 'col-span-full mb-4';
    breadcrumbCard.innerHTML = `
      <div class="bg-gray-50 rounded-lg p-3">
        <a href="?bucket=${encodeURIComponent(currentBucket)}" class="text-blue-600 hover:text-blue-800 flex items-center gap-2">
          <i class="fa fa-level-up-alt"></i>
          <span>Back to root</span>
        </a>
        <div class="text-sm text-gray-600 mt-1">Current folder: ${currentPrefix}</div>
      </div>
    `;
    folderGridEl.appendChild(breadcrumbCard);
  }
  
  // Add folders first
  (data.folders || []).forEach(folder => {
    const fname = folder.split('/').filter(Boolean).pop() + '/';
    const card = createFolderCard(fname, folder);
    folderGridEl.appendChild(card);
  });
  
  // Add files
  (data.files || []).forEach(file => {
    const key = typeof file === 'string' ? file : file.key;
    const size = typeof file === 'object' ? file.size : null;
    const lastMod = typeof file === 'object' ? file.last_modified : null;
    const fname = key.split('/').pop();
    const card = createFileCard(fname, key, size, lastMod);
    folderGridEl.appendChild(card);
  });
}

function createFolderCard(name, path) {
  const card = document.createElement('div');
  card.className = 'bg-white rounded-lg shadow p-4 text-center hover:shadow-lg transition-shadow cursor-pointer folder-card relative';
  
  card.innerHTML = `
    <div class="absolute top-2 left-2">
      <input type="checkbox" class="folder-checkbox rounded" data-path="${path}" data-name="${name}">
    </div>
    <div class="absolute top-2 right-2">
      <button class="three-dots-btn text-gray-400 hover:text-gray-600 p-1 rounded-full hover:bg-gray-100" 
              onclick="event.stopPropagation(); showFolderMenu(event, '${path.replace(/'/g, "\\'")}', '${name.replace(/'/g, "\\'")}')">
        <i class="fa fa-ellipsis-v text-xs"></i>
      </button>
    </div>
    <div class="text-4xl text-blue-500 mb-2">
      <i class="fa fa-folder"></i>
    </div>
    <div class="text-sm font-medium text-gray-900 truncate" title="${name}">${name}</div>
    <div class="text-xs text-gray-500">Folder</div>
  `;
  
  // Add click handler for folder navigation
  card.addEventListener('click', (e) => {
    if (!e.target.closest('.three-dots-btn')) {
      const url = new URL(window.location);
      url.searchParams.set('prefix', path);
      window.location.href = url.toString();
    }
  });
  
  // Add right-click context menu
  card.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    showFolderMenu(e, path, name);
  });
  
  return card;
}

function createFileCard(name, key, size, lastMod) {
  const card = document.createElement('div');
  card.className = 'bg-white rounded-lg shadow p-4 text-center hover:shadow-lg transition-shadow file-card relative';
  
  const icon = getFileIcon(name);
  const sizeText = size != null ? formatBytes(size) : '';
  const dateText = lastMod ? formatDate(lastMod) : '';
  
  card.innerHTML = `
    <div class="absolute top-2 left-2">
      <input type="checkbox" class="file-checkbox rounded" data-key="${key}" data-name="${name}">
    </div>
    <div class="absolute top-2 right-2">
      <button class="three-dots-btn text-gray-400 hover:text-gray-600 p-1 rounded-full hover:bg-gray-100" 
              onclick="event.stopPropagation(); showFileMenu(event, '${key.replace(/'/g, "\\'")}', '${name.replace(/'/g, "\\'")}')">
        <i class="fa fa-ellipsis-v text-xs"></i>
      </button>
    </div>
    <div class="text-4xl text-gray-600 mb-2">
      <i class="fa ${icon}"></i>
    </div>
    <div class="text-sm font-medium text-gray-900 truncate mb-1" title="${name}">${name}</div>
    ${sizeText ? `<div class="text-xs text-gray-500 mb-1">${sizeText}</div>` : ''}
    ${dateText ? `<div class="text-xs text-gray-400">${dateText}</div>` : ''}
 
  `;
  
  // Add right-click context menu
  card.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    showFileMenu(e, key, name);
  });
  
  return card;
}
// store current prefix when user expands a folder (set on click)
function setCurrentPrefix(prefix){
  const cp = document.getElementById('createPrefix'); if (cp) cp.value = prefix;
  const up = document.getElementById('uploadPrefix'); if (up) up.value = prefix;
}
// Hook: when a folder label is clicked and expanded, set prefix
// We piggyback inside renderNode where folders are toggled

// Context menu functions
function showFileMenu(event, key, name) {
  const menu = document.getElementById('contextMenu');
  const menuItems = menu.querySelector('.menu-items');
  
  menuItems.innerHTML = `
    <div class="menu-item" onclick="openFile('${key.replace(/'/g, "\\'")}')">
      <i class="fa fa-eye mr-2"></i> Open File
    </div>
    <div class="menu-item" onclick="downloadFile('${key.replace(/'/g, "\\'")}')">
      <i class="fa fa-download mr-2"></i> Download
    </div>
    <div class="menu-item" onclick="copyFileUrl('${key.replace(/'/g, "\\'")}')">
      <i class="fa fa-link mr-2"></i> Copy URL
    </div>
    <div class="menu-item text-red-600" onclick="selectForDelete('${key.replace(/'/g, "\\'")}')">
      <i class="fa fa-trash mr-2"></i> Delete
    </div>
  `;
  
  showContextMenu(event, menu);
}

function showFolderMenu(event, path, name) {
  const menu = document.getElementById('contextMenu');
  const menuItems = menu.querySelector('.menu-items');
  
  menuItems.innerHTML = `
    <div class="menu-item" onclick="openFolder('${path.replace(/'/g, "\\'")}')">
      <i class="fa fa-folder-open mr-2"></i> Open Folder
    </div>
    <div class="menu-item" onclick="renameFolder('${path.replace(/'/g, "\\'")}', '${name.replace(/'/g, "\\'")}')">
      <i class="fa fa-edit mr-2"></i> Rename
    </div>
    <div class="menu-item text-red-600" onclick="deleteFolder('${path.replace(/'/g, "\\'")}')">
      <i class="fa fa-trash mr-2"></i> Delete
    </div>
  `;
  
  showContextMenu(event, menu);
}

function showContextMenu(event, menu) {
  event.preventDefault();
  
  // Position menu
  const rect = event.target.getBoundingClientRect();
  menu.style.left = (rect.right + 5) + 'px';
  menu.style.top = rect.top + 'px';
  
  // Show menu
  menu.classList.remove('hidden');
  
  // Hide menu when clicking outside
  setTimeout(() => {
    document.addEventListener('click', hideContextMenu);
  }, 100);
}

function hideContextMenu() {
  document.getElementById('contextMenu').classList.add('hidden');
  document.removeEventListener('click', hideContextMenu);
}

// Menu action functions
function openFile(key) {
  const url = `?presign=1&bucket=${encodeURIComponent(currentBucket)}&key=${encodeURIComponent(key)}`;
  window.open(url, '_blank');
  hideContextMenu();
}

function downloadFile(key) {
  const url = `?download=1&bucket=${encodeURIComponent(currentBucket)}&key=${encodeURIComponent(key)}`;
  window.open(url, '_blank');
  hideContextMenu();
}

function copyFileUrl(key) {
  const url = `${window.location.origin}${window.location.pathname}?presign=1&bucket=${encodeURIComponent(currentBucket)}&key=${encodeURIComponent(key)}`;
  navigator.clipboard.writeText(url).then(() => {
    // Show a brief success message
    const toast = document.createElement('div');
    toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg z-50';
    toast.textContent = 'URL copied to clipboard!';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
  });
  hideContextMenu();
}

function openFolder(path) {
  const url = new URL(window.location);
  url.searchParams.set('prefix', path);
  window.location.href = url.toString();
}

function renameFolder(path, name) {
  const newName = prompt('Enter new folder name:', name.replace('/', ''));
  if (newName && newName.trim()) {
    // This would need backend implementation for folder renaming
    alert('Folder renaming not yet implemented. This would rename all files in the folder.');
  }
  hideContextMenu();
}

function deleteFolder(path) {
  if (confirm(`Are you sure you want to delete the folder "${path}" and all its contents?`)) {
    // Create a form to submit folder deletion
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="token" value="${document.querySelector('input[name="token"]').value}">
      <input type="hidden" name="action" value="delete-folder">
      <input type="hidden" name="bucket" value="${currentBucket}">
      <input type="hidden" name="path" value="${path}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
  hideContextMenu();
}

// Multi-select functionality
let selectedItems = new Set();

function updateSelection() {
  const allCheckboxes = document.querySelectorAll('.file-checkbox, .folder-checkbox');
  const checkedCheckboxes = document.querySelectorAll('.file-checkbox:checked, .folder-checkbox:checked');
  
  selectedItems.clear();
  checkedCheckboxes.forEach(checkbox => {
    if (checkbox.dataset.key) {
      selectedItems.add({ type: 'file', key: checkbox.dataset.key, name: checkbox.dataset.name });
    } else if (checkbox.dataset.path) {
      selectedItems.add({ type: 'folder', path: checkbox.dataset.path, name: checkbox.dataset.name });
    }
  });
  
  const selectedCount = selectedItems.size;
  document.getElementById('selectedCount').textContent = selectedCount;
  
  if (selectedCount > 0) {
    document.getElementById('bulkActions').classList.remove('hidden');
  } else {
    document.getElementById('bulkActions').classList.add('hidden');
  }
}

function selectAll() {
  const selectAllCheckbox = document.getElementById('selectAll');
  const allCheckboxes = document.querySelectorAll('.file-checkbox, .folder-checkbox');
  
  allCheckboxes.forEach(checkbox => {
    checkbox.checked = selectAllCheckbox.checked;
  });
  
  updateSelection();
  
  // Update select all checkbox state
  if (allCheckboxes.length > 0) {
    const checkedCount = document.querySelectorAll('.file-checkbox:checked, .folder-checkbox:checked').length;
    selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
  }
}

function deleteSelected() {
  if (selectedItems.size === 0) return;
  
  const itemList = Array.from(selectedItems).map(item => item.name).join('\n');
  if (confirm(`Are you sure you want to delete the following items?\n\n${itemList}`)) {
    showProgressBar('Deleting items...');
    
    // Create a form to submit multiple deletions
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="token" value="${document.querySelector('input[name="token"]').value}">
      <input type="hidden" name="action" value="bulk-delete">
      <input type="hidden" name="bucket" value="${currentBucket}">
      <input type="hidden" name="items" value="${JSON.stringify(Array.from(selectedItems))}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}

function downloadSelected() {
  if (selectedItems.size === 0) return;
  
  if (selectedItems.size === 1) {
    const item = Array.from(selectedItems)[0];
    if (item.type === 'file') {
      downloadFile(item.key);
    }
  } else {
    // For multiple files, create individual download links
    const fileItems = Array.from(selectedItems).filter(item => item.type === 'file');
    if (fileItems.length > 0) {
      fileItems.forEach(item => {
        const url = `?download=1&bucket=${encodeURIComponent(currentBucket)}&key=${encodeURIComponent(item.key)}`;
        const link = document.createElement('a');
        link.href = url;
        link.download = item.name;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
      
      const toast = document.createElement('div');
      toast.className = 'fixed top-4 right-4 bg-blue-500 text-white px-4 py-2 rounded shadow-lg z-50';
      toast.textContent = `Downloading ${fileItems.length} file(s)...`;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3000);
    }
  }
}

function copySelectedUrls() {
  if (selectedItems.size === 0) return;
  
  const urls = Array.from(selectedItems).map(item => {
    if (item.type === 'file') {
      return `${window.location.origin}${window.location.pathname}?presign=1&bucket=${encodeURIComponent(currentBucket)}&key=${encodeURIComponent(item.key)}`;
    } else {
      return `${window.location.origin}${window.location.pathname}?bucket=${encodeURIComponent(currentBucket)}&prefix=${encodeURIComponent(item.path)}`;
    }
  }).join('\n');
  
  navigator.clipboard.writeText(urls).then(() => {
    const toast = document.createElement('div');
    toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg z-50';
    toast.textContent = `${selectedItems.size} URL(s) copied to clipboard!`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
  });
}

function bulkRename() {
  if (selectedItems.size === 0) return;
  
  const prefix = prompt('Enter prefix to add to all selected items (or leave empty for no change):');
  if (prefix === null) return; // User cancelled
  
  const suffix = prompt('Enter suffix to add to all selected items (or leave empty for no change):');
  if (suffix === null) return; // User cancelled
  
  if (!prefix && !suffix) {
    alert('No changes specified.');
    return;
  }
  
  showProgressBar('Renaming items...');
  
  // Create a form to submit bulk rename
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="token" value="${document.querySelector('input[name="token"]').value}">
    <input type="hidden" name="action" value="bulk-rename">
    <input type="hidden" name="bucket" value="${currentBucket}">
    <input type="hidden" name="items" value="${JSON.stringify(Array.from(selectedItems))}">
    <input type="hidden" name="prefix" value="${prefix}">
    <input type="hidden" name="suffix" value="${suffix}">
  `;
  document.body.appendChild(form);
  form.submit();
}

function bulkMove() {
  if (selectedItems.size === 0) return;
  
  const targetFolder = prompt('Enter target folder path (e.g., "newfolder/" or leave empty for root):');
  if (targetFolder === null) return; // User cancelled
  
  if (confirm(`Are you sure you want to move ${selectedItems.size} item(s) to "${targetFolder || 'root'}"?`)) {
    showProgressBar('Moving items...');
    
    // Create a form to submit bulk move
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="token" value="${document.querySelector('input[name="token"]').value}">
      <input type="hidden" name="action" value="bulk-move">
      <input type="hidden" name="bucket" value="${currentBucket}">
      <input type="hidden" name="items" value="${JSON.stringify(Array.from(selectedItems))}">
      <input type="hidden" name="target_folder" value="${targetFolder}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}

function showProgressBar(message) {
  // Remove existing progress bar if any
  const existingProgress = document.getElementById('bulkProgress');
  if (existingProgress) {
    existingProgress.remove();
  }
  
  const progressBar = document.createElement('div');
  progressBar.id = 'bulkProgress';
  progressBar.className = 'fixed top-4 left-1/2 transform -translate-x-1/2 bg-blue-500 text-white px-6 py-3 rounded shadow-lg z-50 flex items-center gap-3';
  progressBar.innerHTML = `
    <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
    <span>${message}</span>
  `;
  document.body.appendChild(progressBar);
  
  // Auto-hide after 5 seconds (operations should complete by then)
  setTimeout(() => {
    if (progressBar.parentNode) {
      progressBar.remove();
    }
  }, 5000);
}

function clearSelection() {
  const allCheckboxes = document.querySelectorAll('.file-checkbox:checked, .folder-checkbox:checked');
  allCheckboxes.forEach(checkbox => {
    checkbox.checked = false;
  });
  
  const selectAllCheckbox = document.getElementById('selectAll');
  if (selectAllCheckbox) {
    selectAllCheckbox.checked = false;
    selectAllCheckbox.indeterminate = false;
  }
  
  updateSelection();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
  // Ctrl+A to select all
  if (e.ctrlKey && e.key === 'a') {
    e.preventDefault();
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
      selectAllCheckbox.checked = true;
      selectAll();
    }
  }
  
  // Escape to clear selection
  if (e.key === 'Escape') {
    clearSelection();
  }
  
  // Delete key to delete selected items
  if (e.key === 'Delete' && selectedItems.size > 0) {
    e.preventDefault();
    deleteSelected();
  }
});

  // Add event listeners for checkboxes
  document.addEventListener('change', function(e) {
    if (e.target.matches('.file-checkbox, .folder-checkbox')) {
      updateSelection();
      
      // Add visual feedback for selected items
      const card = e.target.closest('.file-card, .folder-card');
      if (card) {
        if (e.target.checked) {
          card.classList.add('selected');
        } else {
          card.classList.remove('selected');
        }
      }
    }
  });

// Add event listener for select all checkbox
document.addEventListener('change', function(e) {
  if (e.target.matches('#selectAll')) {
    selectAll();
  }
});

// init
loadRoot();

// Restore last selected view
const lastView = localStorage.getItem('s3filemanager_view') || 'tree';
if (lastView === 'folder') {
  switchView('folder');
}

// Fetch bucket sizes lazily and show near names
(async function fetchBucketSizes(){
  const links = document.querySelectorAll('a[href^="?bucket="]');
  for (const link of links) {
    const url = new URL(link.href, location.href);
    const bucket = url.searchParams.get('bucket');
    const sizeSpan = document.getElementById('size-' + bucket);
    if (!bucket || !sizeSpan) continue;
    try {
      const res = await fetch(`?bucket_size=1&bucket=${encodeURIComponent(bucket)}`);
      const data = await res.json();
      if (data && Number.isFinite(data.bytes)) {
        sizeSpan.textContent = `(${formatBytes(data.bytes)})`;
      }
    } catch {}
  }
})();
</script>
</body>
</html>
