<?php

declare(strict_types=1);

namespace PhpRetention;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class Retention implements LoggerAwareInterface
{

    /**
     * @var int keep last n (most recent) files
     */
    private int $keepLast;

    /**
     * @var int for the last n hours in which a file was made, keep only the last snapshot for each hour
     */
    private int $keepHourly;

    /**
     * @var int for the last n days which have one or more files, only keep the last one for that day
     */
    private int $keepDaily;

    /**
     * @var int for the last n weeks which have one or more files, only keep the last one for that week
     */
    private int $keepWeekly;

    /**
     * @var int for the last n months which have one or more files, only keep the last one for that month
     */
    private int $keepMonthly;

    /**
     * @var int for the last n years which have one or more files, only keep the last one for that year
     */
    private int $keepYearly;

    /**
     * @var bool dry run to test before executing
     */
    private bool $dryRun;

    /**
     * function to execute when pruning the file
     *
     * @var callable
     */
    private $pruneHandler;

    /**
     * function to get time of the file
     *
     * @var callable
     */
    private $timeHandler;

    /**
     * function to find files
     *
     * @var callable
     */
    private $findHandler;

    /**
     * @var string|null regex to exclude files (applies to full file path)
     */
    private ?string $excludePattern;

    /**
     * @var string|null set regex pattern for directory name to group files so that retention will be applied based on directory name
     */
    private ?string $groupPattern;

    private LoggerInterface $logger;

    /**
     * Retention constructor.
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
        $this->logger = new NullLogger();
    }

    /**
     * Example: Full backup on each sunday, no backups between
     * KeepLast=2 + KeepDaily=2 : keep file1 and file2
     * KeepDaily=1 + KeepMonthly=2 : keep file1 + file2
     *  ID        Time
     * -----------------------------
     * file7  2019-09-01 11:00:00
     * file6  2019-09-08 11:00:00
     * file5  2019-09-15 11:00:00
     * file4  2019-09-22 11:00:00
     * file3  2019-09-29 11:00:00
     * file2  2019-10-06 11:00:00
     * file1  2019-10-13 11:00:00.
     *
     * @return self
     */
    public function setConfig(array $config)
    {
        $this->keepLast = isset($config['keep-last']) ? intval($config['keep-last']) : 0;
        $this->keepHourly = isset($config['keep-hourly']) ? intval($config['keep-hourly']) : 0;
        $this->keepDaily = isset($config['keep-daily']) ? intval($config['keep-daily']) : 0;
        $this->keepWeekly = isset($config['keep-weekly']) ? intval($config['keep-weekly']) : 0;
        $this->keepMonthly = isset($config['keep-monthly']) ? intval($config['keep-monthly']) : 0;
        $this->keepYearly = isset($config['keep-yearly']) ? intval($config['keep-yearly']) : 0;

        $this->keepLast = max($this->keepLast, 0);
        $this->keepHourly = max($this->keepHourly, 0);
        $this->keepDaily = max($this->keepDaily, 0);
        $this->keepWeekly = max($this->keepWeekly, 0);
        $this->keepMonthly = max($this->keepMonthly, 0);
        $this->keepYearly = max($this->keepYearly, 0);

        if ($this->keepLast === 0) {
            // never delete all files
            $this->keepLast = 1;
        }

        $this->groupPattern = isset($config['group-pattern']) ? (string) $config['group-pattern'] : null;
        $this->excludePattern = isset($config['exclude-pattern']) ? (string) $config['exclude-pattern'] : null;
        $this->dryRun = isset($config['dry-run']) && $config['dry-run'];

        return $this;
    }

    /**
     * enable/disable dry-run.
     * @param bool $dryRun
     * @return $this
     */
    public function setDryRun(bool $dryRun)
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * exclude files using regex
     * @param string $pattern
     * @return void
     */
    public function setExcludePattern(string $pattern)
    {
        $this->excludePattern = $pattern;
    }

    /**
     * apply retention policy under specified root directory.
     * @param string $baseDir
     * @return array
     */
    public function apply(string $baseDir)
    {
        $files = $this->findFiles($baseDir);
        $result = $this->checkPolicy($files);
        $keepList = $result['keep'];
        $pruneList = $result['prune'];

        foreach ($keepList as $keep) {
            /** @var FileInfo $fileInfo */
            $fileInfo = $keep['fileInfo'];
            $this->logger->debug("{$fileInfo->path} will be kept for " . implode(', ', $keep['reasons']) . ' policies.');
        }

        foreach ($pruneList as $fileInfo) {
            /** @var FileInfo $fileInfo */
            $this->logger->debug("{$fileInfo->path} will be removed.");
        }

        if (empty($keepList)) {
            $this->logger->error('There must be at least one file to keep.', [
                'baseDir' => $baseDir,
            ]);

            throw new RuntimeException('There must be at least one file to keep.');
        }

        if ($this->dryRun) {
            $this->logger->debug('No policy applied because of dry-run.');
        }
        else {
            foreach ($pruneList as $fileInfo) {
                /** @var FileInfo $fileInfo */
                if (!$this->pruneFile($fileInfo)) {
                    throw new RuntimeException("Pruning {$fileInfo->path} failed unexpectedly.");
                }
            }
        }

        return $keepList;
    }

    /**
     * check each file for retention
     * only the newest backups for each period will be kept (for daily, the latest backup of the day if there are multiple).
     * @param FileInfo[] $files array of FileInfo objects
     * @return array[]
     */
    private function checkPolicy(array $files)
    {
        $keepList = [];
        $pruneList = [];

        $lastList = [];
        $hourlyList = [];
        $dailyList = [];
        $weeklyList = [];
        $monthlyList = [];
        $yearlyList = [];

        // from newest to oldest
        foreach ($files as $fileInfo) {
            $filepath = $fileInfo->path;
            $hour = $fileInfo->hour;
            $day = $fileInfo->day;
            $week = $fileInfo->week;
            $month = $fileInfo->month;
            $year = $fileInfo->year;

            $hourIndex = $year . $month . $day . $hour;
            $dayIndex = $year . $month . $day;
            $weekIndex = $year . $week;
            $monthIndex = $year . $month;
            $yearIndex = $year;

            $keep = false;
            $reasons = [];

            if ($this->keepLast) {
                $keepCount = count($lastList);
                if ($this->keepLast > $keepCount) {
                    $keep = true;
                    $lastList[] = $filepath;
                    $reasons[] = 'last';
                }
            }

            if ($this->keepHourly && $hour > 0) {
                $keepCount = count($hourlyList);
                if ($this->keepHourly > $keepCount) {
                    if (!isset($hourlyList[$hourIndex])) {
                        $keep = true;
                        $reasons[] = 'hourly';
                    }
                }
            }

            if ($this->keepDaily && $day > 0) {
                $keepCount = count($dailyList);
                if ($this->keepDaily > $keepCount) {
                    if (!isset($dailyList[$dayIndex])) {
                        $keep = true;
                        $reasons[] = 'daily';
                    }
                }
            }

            if ($this->keepWeekly && $week > 0) {
                $keepCount = count($weeklyList);
                if ($this->keepWeekly > $keepCount) {
                    if (!isset($weeklyList[$weekIndex])) {
                        $keep = true;
                        $reasons[] = 'weekly';
                    }
                }
            }

            if ($this->keepMonthly && $month > 0) {
                $keepCount = count($monthlyList);
                if ($this->keepMonthly > $keepCount) {
                    if (!isset($monthlyList[$monthIndex])) {
                        $keep = true;
                        $reasons[] = 'monthly';
                    }
                }
            }

            if ($this->keepYearly && $year > 0) {
                $keepCount = count($yearlyList);
                if ($this->keepYearly > $keepCount) {
                    if (!isset($yearlyList[$yearIndex])) {
                        $keep = true;
                        $reasons[] = 'yearly';
                    }
                }
            }

            // mark this file processed for all "keep" periods configured

            if ($this->keepHourly) {
                if (!isset($hourlyList[$hourIndex])) {
                    $hourlyList[$hourIndex] = $filepath;
                }
            }

            if ($this->keepDaily) {
                if (!isset($dailyList[$dayIndex])) {
                    $dailyList[$dayIndex] = $filepath;
                }
            }

            if ($this->keepWeekly) {
                if (!isset($weeklyList[$weekIndex])) {
                    $weeklyList[$weekIndex] = $filepath;
                }
            }

            if ($this->keepMonthly) {
                if (!isset($monthlyList[$monthIndex])) {
                    $monthlyList[$monthIndex] = $filepath;
                }
            }

            if ($this->keepYearly) {
                if (!isset($yearlyList[$yearIndex])) {
                    $yearlyList[$yearIndex] = $filepath;
                }
            }

            if ($keep) {
                $keepList[] = [
                    'fileInfo' => $fileInfo,
                    'reasons' => $reasons,
                ];
            }
            else {
                $pruneList[] = $fileInfo;
            }
        }

        if (empty($keepList)) {
            // always keep at least 1
            if (count($files) > 0) {
                $keepList[] = [
                    'fileInfo' => $files[0],
                    'reasons' => ['last']
                ];
            }
        }

        return [
            'keep' => $keepList,
            'prune' => $pruneList,
        ];
    }

    /**
     * find list of files to apply retention policy
     * @param string $targetDir
     * @return array
     */
    public function findFiles(string $targetDir)
    {
        if (is_callable($this->findHandler)) {
            $fn = $this->findHandler;

            $files = $fn($targetDir);
        }
        else {
            $files = [];

            $groupPattern = $this->groupPattern;
            $excludePattern = $this->excludePattern;

            foreach (scandir($targetDir) as $file) {
                if (in_array($file, ['.', '..'])) {
                    continue;
                }

                $filepath = "{$targetDir}/{$file}";

                if (!empty($excludePattern)) {
                    if (preg_match($excludePattern, $filepath)) {
                        continue;
                    }
                }

                $found = false;

                // trailing slash for cloud storage
                if (str_ends_with($filepath, '/') || is_dir($filepath)) {
                    $isDirectory = true;

                    // check basename for group pattern
                    if (!empty($groupPattern)) {
                        if (preg_match($groupPattern, basename($filepath))) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        $filesRecursive = $this->findFiles($filepath);
                        if (!empty($filesRecursive)) {
                            $files = array_merge($files, $filesRecursive);
                        }
                    }
                }
                else {
                    $isDirectory = false;
                    $found = true;
                }

                if ($found) {
                    $fileInfo = $this->checkFile($filepath, $isDirectory);
                    if (is_null($fileInfo)) {
                        continue;
                    }
                    $files[] = $fileInfo;
                }
            }
        }

        // sort files by descending order (from newest to oldest)
        usort($files, function (FileInfo $a, FileInfo $b) {
            return $b->timestamp - $a->timestamp ? -1 : 1;
        });

        return $files;
    }

    /**
     * check file and get time info (UTC time!)
     * @param string $filepath
     * @param bool $isDirectory
     * @return FileInfo|null
     */
    protected function checkFile(string $filepath, bool $isDirectory = false): ?FileInfo
    {
        if (is_callable($this->timeHandler)) {
            $fn = $this->timeHandler;
            $fileInfo = $fn($filepath, $isDirectory);

            return $fileInfo;
        }
        else {
            $stats = stat($filepath);
            $timeCreated = $stats['mtime'] ?: $stats['ctime'];

            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date = $date->setTimestamp($timeCreated);

            return new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: $isDirectory
            );
        }
    }

    /**
     * run prune action (by default try to delete local file)
     *
     * @param FileInfo $fileInfo
     * @return bool
     */
    protected function pruneFile(FileInfo $fileInfo)
    {
        if (is_callable($this->pruneHandler)) {
            $fn = $this->pruneHandler;
            $rs = $fn($fileInfo);
        }
        else {
            try {
                $pathToDelete = $fileInfo->path;
                if ($fileInfo->isDirectory) {
                    // basic safeguard against deleting unix root/system folders
                    // most of the time backup dir will be at least /path/to/foo
                    if (substr_count(realpath($pathToDelete), '/') < 3) {
                        throw new RuntimeException("'{$pathToDelete}' is marked as risky, hence pruning is not allowed.");
                    }
                    $directory = new RecursiveDirectoryIterator($pathToDelete);
                    $iterator = new RecursiveIteratorIterator($directory);
                    $dirsToDelete = [];
                    foreach ($iterator as $info) {
                        $pathToDelete = $info->getPathName();
                        if (!$info->isDir()) {
                            unlink($pathToDelete);
                        }
                        else {
                            $dirsToDelete[] = $info->getPathName();
                        }
                    }
                    foreach (array_reverse($dirsToDelete) as $pathToDelete) {
                        rmdir($pathToDelete);
                    }

                    $pathToDelete = $fileInfo->path;
                    $rs = rmdir($pathToDelete);
                }
                else {
                    $rs = unlink($pathToDelete);
                }
            }
            catch (Throwable $ex) {
                throw new RetentionError("'{$pathToDelete}' could not be pruned: " . $ex->getMessage());
            }
        }

        return (bool) $rs;
    }

    /**
     * change default prune action
     * @param callable $callback
     */
    public function setPruneHandler(callable $callback)
    {
        $this->pruneHandler = $callback;
    }

    /**
     * change default time calculation
     * @param callable $callback
     */
    public function setTimeHandler(callable $callback)
    {
        $this->timeHandler = $callback;
    }

    /**
     * set finder
     * @param callable $callback
     */
    public function setFindHandler(callable $callback)
    {
        $this->findHandler = $callback;
    }

    /**
     * set regex pattern for directory name to group files so that retention will be applied based on directory name
     * @param string $regexPattern
     */
    public function setGroupPattern(string $regexPattern)
    {
        $this->groupPattern = $regexPattern;
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
