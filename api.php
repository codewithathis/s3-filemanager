<?php
require 'vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Function to read credentials from credentials.ini
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
    $credentials = getCredentials('default');
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
    sendJson(['error' => $e->getMessage()]);
    exit;
}

header('Content-Type: application/json');

// Helper: Send JSON response
function sendJson($data) {
    echo json_encode($data);
    exit;
}

// Route actions
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'listBuckets':
            $buckets = $s3->listBuckets();
            sendJson($buckets['Buckets']);
            break;

        case 'listObjects':
            $bucket = $_GET['bucket'] ?? '';
            $prefix = $_GET['prefix'] ?? '';
            if (!$bucket) sendJson(['error' => 'Bucket is required']);
            $result = $s3->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $prefix
            ]);
            sendJson($result['Contents'] ?? []);
            break;

        case 'upload':
            $bucket = $_POST['bucket'] ?? '';
            $key = $_POST['key'] ?? '';
            if (!$bucket || !isset($_FILES['file'])) sendJson(['error' => 'Bucket and file are required']);
            $filePath = $_FILES['file']['tmp_name'];
            $s3->putObject([
                'Bucket' => $bucket,
                'Key'    => $key ?: $_FILES['file']['name'],
                'SourceFile' => $filePath
            ]);
            sendJson(['success' => true]);
            break;

        case 'delete':
            $bucket = $_POST['bucket'] ?? '';
            $key = $_POST['key'] ?? '';
            if (!$bucket || !$key) sendJson(['error' => 'Bucket and key are required']);
            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $key
            ]);
            sendJson(['success' => true]);
            break;

        case 'download':
            $bucket = $_GET['bucket'] ?? '';
            $key = $_GET['key'] ?? '';
            if (!$bucket || !$key) sendJson(['error' => 'Bucket and key are required']);
            $cmd = $s3->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $key
            ]);
            $req = $s3->createPresignedRequest($cmd, '+20 minutes');
            sendJson(['url' => (string) $req->getUri()]);
            break;

        default:
            sendJson(['error' => 'Unknown action']);
    }
} catch (AwsException $e) {
    sendJson(['error' => $e->getAwsErrorMessage()]);
}
