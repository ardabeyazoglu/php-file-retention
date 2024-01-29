<?php

// Example: custom finder function that finds files in ftp by using a custom function instead of scanning the file system
// Example: custom prune function that deletes files from ftp

use PhpRetention\FileInfo;
use PhpRetention\Retention;

require_once __DIR__ . "/../vendor/autoload.php";

$ftpConnection = ftp_connect("ftp.example.com");
if (!$ftpConnection) {
    die('Could not connect to the FTP server');
}
$loginResult = ftp_login($ftpConnection, "ftp-user", "ftp-pass");
if (!$loginResult) {
    die('FTP login failed');
}

$ret = new Retention([
    'keep-last' => 2
]);
$ret->setFindHandler(function (string $targetDir) use ($ftpConnection) {
    $files = [];
    $fileList = ftp_mlsd($ftpConnection, $targetDir) ?: [];
    foreach ($fileList as $file) {
        $filename = $file['name'];
        $time = (int) $file['modify'];

        if (preg_match('/^backup_\w+\.zip$/', $filename)) {
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date->setTimestamp($time);

            $filepath = "$targetDir/$filename";

            $files[] = new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: false
            );
        }
    }

    return $files;
});
$ret->setPruneHandler(function (FileInfo $fileInfo) use ($ftpConnection) {
    ftp_delete($ftpConnection, $fileInfo->path);
});
$ret->apply("/path/to/dir");

ftp_close($ftpConnection);