# php-retention

A simple but handy php library to apply retention policy to files before deleting, archiving or anything that is possible with a custom callback. 
A typical example would be backup archiving based on custom policies such as "keep last 7 daily, 2 weekly and 3 monthly backups".

# Features

- Apply hourly, daily, weekly, monthly and yearly policies
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

    // define retention policy
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

# Examples

### 1. Custom Finder

### 2. Custom Time Comparisons

### 3. Custom Prune Handler

### 4. Grouping Files With Regexp

# Contribution

Feel free to post an issue, fork and send a pull request.
    
# ToDo

- [ ] Move custom file objects into their own interfaces
- [ ] Write separate tests for each feature
- [ ] Add release system and integrate with packagist