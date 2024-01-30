<?php

// Example: custom finder function that finds files in ftp by using a custom function instead of scanning the file system
// Example: custom prune function that deletes files from ftp (use xlight ftp server for quick testing)

use PhpRetention\FileInfo;
use PhpRetention\Retention;

require_once __DIR__ . "/../vendor/autoload.php";

$ftpConnection = ftp_connect("localhost");
if (!$ftpConnection) {
    die('Could not connect to the FTP server');
}
$loginResult = ftp_login($ftpConnection, "test", "123456");
if (!$loginResult) {
    die('FTP login failed');
}

$ret = new Retention([
    'keep-daily' => 3,
    'keep-weekly' => 2
]);
$ret->setFindHandler(function (string $targetDir) use ($ftpConnection) {
    $files = [];
    $fileList = ftp_mlsd($ftpConnection, $targetDir) ?: [];
    foreach ($fileList as $file) {
        $filename = $file['name'];
        if (preg_match('/^backup\-\w+$/', $filename)) {
            if (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $file['modify'], $matches)) {
                $year = intval($matches[1]);
                $month = intval($matches[2]);
                $day = intval($matches[3]);

                $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $date = $date->setDate($year, $month, $day)->setTime(0, 0, 0);

                $filepath = "$targetDir/$filename";
                $files[] = new FileInfo(
                    date: $date,
                    path: $filepath,
                    isDirectory: false
                );
            }
        }
    }

    return $files;
});
$ret->setPruneHandler(function (FileInfo $fileInfo) use ($ftpConnection) {
    return ftp_delete($ftpConnection, $fileInfo->path);
});
$keptFiles = $ret->apply("/backup");

print_r($keptFiles);

ftp_close($ftpConnection);