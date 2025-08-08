<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function getCredentials($profile = 'default') {
    $credentialsFile = 'credentials.ini';
    if (!file_exists($credentialsFile)) {
        throw new Exception("Credentials file not found: $credentialsFile");
    }
    $credentials = parse_ini_file($credentialsFile, true);
    if (!isset($credentials[$profile])) {
        throw new Exception("Profile '$profile' not found in credentials file");
    }
    return $credentials[$profile];
}

try {
    $credentials = getCredentials();
    $s3 = new S3Client([
        'credentials' => [
            'key'    => $credentials['aws_access_key_id'],
            'secret' => $credentials['aws_secret_access_key'],
        ],
        'region'  => $credentials['region'],
        'version' => '2006-03-01',
        'endpoint' => $credentials['endpoint'],
        'use_path_style_endpoint' => true
    ]);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Handle actions (delete, create, rename, etc.)
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bucket = $_POST['bucket'] ?? '';
    $key = $_POST['key'] ?? '';

    try {
        if ($action === 'delete-file') {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $actionMsg = "File deleted: $key";
        } elseif ($action === 'delete-bucket') {
            $s3->deleteBucket(['Bucket' => $bucket]);
            $actionMsg = "Bucket deleted: $bucket";
        } elseif ($action === 'create-folder') {
            $folderName = rtrim($_POST['folder_name'], '/') . '/';
            $s3->putObject(['Bucket' => $bucket, 'Key' => $folderName]);
            $actionMsg = "Folder created: $folderName";
        } elseif ($action === 'rename-object') {
            $newName = $_POST['new_name'];
            $s3->copyObject([
                'Bucket' => $bucket,
                'CopySource' => "$bucket/$key",
                'Key' => $newName
            ]);
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $actionMsg = "Renamed to: $newName";
        }
    } catch (AwsException $e) {
        $actionMsg = "Error: " . $e->getMessage();
    }
}

// List buckets
$buckets = $s3->listBuckets();
$currentBucket = $_GET['bucket'] ?? '';
$objects = [];
if ($currentBucket) {
    $objects = $s3->listObjectsV2(['Bucket' => $currentBucket]);
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>AWS S3 File Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: Arial; background: #f9f9f9; margin: 0; padding: 0; }
        header { background: #232f3e; color: white; padding: 10px; }
        h1 { margin: 0; font-size: 20px; display: inline-block; }
        .container { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px; }
        tr:hover { background: #f1f1f1; }
        .icon { margin-right: 6px; }
        .actions button { margin-right: 5px; }
        form.inline { display: inline; }
        .message { padding: 10px; margin: 10px 0; background: #e1f5fe; border-left: 4px solid #0288d1; }
    </style>
</head>
<body>
<header>
    <h1><i class="fa-brands fa-aws"></i> AWS S3 File Manager</h1>
</header>
<div class="container">
    <?php if ($actionMsg): ?>
        <div class="message"><?= htmlspecialchars($actionMsg) ?></div>
    <?php endif; ?>

    <h2>Buckets</h2>
    <ul>
        <?php foreach ($buckets['Buckets'] as $bucket): ?>
            <li>
                <i class="fa fa-database icon"></i>
                <a href="?bucket=<?= urlencode($bucket['Name']) ?>"><?= htmlspecialchars($bucket['Name']) ?></a>
                <form class="inline" method="post">
                    <input type="hidden" name="action" value="delete-bucket">
                    <input type="hidden" name="bucket" value="<?= htmlspecialchars($bucket['Name']) ?>">
                    <button type="submit" onclick="return confirm('Delete bucket?')"><i class="fa fa-trash"></i></button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($currentBucket): ?>
        <h2>Objects in Bucket: <?= htmlspecialchars($currentBucket) ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="create-folder">
            <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
            <input type="text" name="folder_name" placeholder="New folder name" required>
            <button type="submit"><i class="fa fa-folder-plus"></i> Create Folder</button>
        </form>
        <table>
            <tr>
                <th><input type="checkbox" onclick="toggleAll(this)"></th>
                <th>Name</th>
                <th>Actions</th>
            </tr>
            <?php if (!empty($objects['Contents'])): ?>
                <?php foreach ($objects['Contents'] as $obj): ?>
                    <?php $name = $obj['Key']; ?>
                    <tr>
                        <td><input type="checkbox" name="selected[]" value="<?= htmlspecialchars($name) ?>"></td>
                        <td>
                            <?php if (substr($name, -1) === '/'): ?>
                                <i class="fa fa-folder icon"></i>
                            <?php else: ?>
                                <i class="fa fa-file icon"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($name) ?>
                        </td>
                        <td class="actions">
                            <form class="inline" method="post">
                                <input type="hidden" name="action" value="delete-file">
                                <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($name) ?>">
                                <button type="submit" onclick="return confirm('Delete this?')"><i class="fa fa-trash"></i></button>
                            </form>
                            <form class="inline" method="post">
                                <input type="hidden" name="action" value="rename-object">
                                <input type="hidden" name="bucket" value="<?= htmlspecialchars($currentBucket) ?>">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($name) ?>">
                                <input type="text" name="new_name" placeholder="Rename to..." required>
                                <button type="submit"><i class="fa fa-edit"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    <?php endif; ?>
</div>
<script>
function toggleAll(source) {
    checkboxes = document.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>
</body>
</html>
