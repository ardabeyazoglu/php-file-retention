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

### 2. Custom Time Comparisons

### 3. Custom Prune Handler

### 4. Grouping Files With Regexp

# Contribution

Feel free to post an issue if you encounter a bug or you want to implement a new feature. 
Please be descriptive in your posts.
    
# ToDo

- [ ] Document everyting
- [ ] Add release system and integrate with packagist
- [ ] Add keep-within-*** policy support
- [ ] Add console support