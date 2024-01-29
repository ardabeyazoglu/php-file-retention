# php-retention

A simple but handy php library to apply retention policy to files before deleting, archiving or anything that is possible with a custom callback. 
A typical example would be backup archiving based on custom policies such as "keep last 7 daily, 2 weekly and 3 monthly backups".

# Features

- Apply hourly, daily, weekly, monthly and yearly policies (always UTC timezone)
- Customize prune action to do something else instead of deleting (e.g. move them to cloud)
- Customize file finder logic (e.g. support different storage interfaces such as S3)
- Grouping files (e.g. to delete parent directory instead of a single file)
- Dry runnable
- Logger aware
- Very small, no dependencies

# Install

    composer require ardabeyazoglu:php-retention

# Test

    composer test

# Usage

    // define retention policy (UTC timezone)
    $retention = new PhpRetention\Retention([
        "keep-daily" => 7,
        "keep-weekly" => 4,
        "keep-monthly" => 6,
        "keep-yearly" => 2
    ]);

    // customize finder logic if required
    $retention->setFindHandler(function () {});

    // customize time calculation if required
    $retention->setTimeHandler(function () {});

    // customize time calculation if required
    $retention->setPruneHandler(function () {});

    // apply retention in given directory (this WILL PRUNE the files!)
    $result = $retention->apply("/path/to/files");
    print_r($result);

# Policy Configuration

This library is inspired by [Restic's policy model](https://restic.readthedocs.io/en/latest/060_forget.html#removing-snapshots-according-to-a-policy). 
Policy configuration without understanding how it works might be misleading. Please read the [explanation](https://restic.readthedocs.io/en/latest/060_forget.html#removing-snapshots-according-to-a-policy) to understand how each `keep-***` parameter works. 

    keep-last: keep the most recent N files. (default: 1)
    keep-hourly: for the last N hours which have one or more files, keep only the most recent one for each hour.
    keep-daily: for the last N days which have one or more files, keep only the most recent one for each day.
    keep-weekly: for the last N weeks which have one or more files, keep only the most recent one for each week.
    keep-monthly: for the last N months which have one or more files, keep only the most recent one for each month.
    keep-yearly: for the last N years which have one or more files, keep only the most recent one for each year.

# Examples

### 1. Custom Finder

    ```php
    $rets->setFindHandler(function (string $targetDir) use ($ftpConnection) {
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
    ```

### 2. Custom Time Parser

    ```php
    $ret->setTimeHandler(function (string $filepath, bool $isDirectory) {
        // assume the files waiting for retention have this format: "backup@YYYYmmdd"
        if (preg_match('/^backup@([0-9]{4})([0-9]{2})([0-9]{2})$/', $filepath, $matches)) {
            $year = intval($matches[0]);
            $month = intval($matches[1]);
            $day = intval($matches[2]);
    
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date->setDate($year, $month, $day)->setTime(0, 0, 0, 0);
    
            return new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: $isDirectory
            );
        }
        else {
            return null;
        }
    });
    ```

### 3. Custom Prune Handler

    ```php
    $ret->setPruneHandler(function (FileInfo $fileInfo) use ($ftpConnection) {
        ftp_delete($ftpConnection, $fileInfo->path);
    });
    ```

### 4. Grouping Files With Regexp

    ```php
    $ret->setGroupPattern(function () {
        // TODO
    });
    ```

# Contribution

Feel free to post an issue if you encounter a bug or you want to implement a new feature. 
Please be descriptive in your posts.
    
# ToDo

- [ ] Add group pattern support by custom function (or use "tagging" keyword)
- [ ] Test grouping/tagging and custom pruning
- [ ] Add console support
- [ ] Add release system and integrate with packagist and codecov
- [ ] Add keep-within-*** policy support