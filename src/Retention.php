<?php

declare(strict_types=1);

namespace PhpRetention;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Implemented similarly to https://restic.readthedocs.io/en/latest/060_forget.html
 * Class Retention.
 */
class Retention
{

    /**
     * @var int never delete the n last (most recent) snapshots
     */
    private int $keepLast;

    /**
     * @var int for the last n hours in which a snapshot was made, keep only the last snapshot for each hour
     */
    private int $keepHourly;

    /**
     * @var int for the last n days which have one or more snapshots, only keep the last one for that day
     */
    private int $keepDaily;

    /**
     * @var int for the last n weeks which have one or more snapshots, only keep the last one for that week
     */
    private int $keepWeekly;

    /**
     * @var int for the last n months which have one or more snapshots, only keep the last one for that month
     */
    private int $keepMonthly;

    /**
     * @var int for the last n years which have one or more snapshots, only keep the last one for that year
     */
    private int $keepYearly;

    /**
     * @var bool dry run to test before executing
     */
    private bool $dryRun;

    /**
     * function to execute when pruning the file.
     *
     * @var callable
     */
    private $pruneHandler;

    /**
     * function to get time of the file.
     *
     * @var callable
     */
    private $timeHandler;

    /**
     * function to find files.
     *
     * @var callable
     */
    private $findHandler;

    /**
     * @var string regex to exclude files (applies to full file path)
     */
    private string $excludePattern;

    /**
     * @var string set regex pattern for directory name to group files so that retention will be applied based on directory name
     */
    private string $groupPattern;

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

        if ($this->keepLast === 0) {
            // never delete all backups
            $this->keepLast = 1;
        }

        // $this->removeEmptyDirs = isset($config['remove-empty-dirs']) && $config['remove-empty-dirs'];
        $this->excludePattern = isset($config['exclude-pattern']) ? (string) $config['exclude-pattern'] : null;
        $this->dryRun = isset($config['dry-run']) && $config['dry-run'];

        return $this;
    }

    /**
     * enable/disable dryrun.
     *
     * @return self
     */
    public function setDryRun(bool $dryRun)
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function setExcludePattern(string $pattern)
    {
        $this->excludePattern = $pattern;
    }

    /**
     * apply retention policy under specified root directory.
     *
     * @return array list of kept files
     *
     * @throws Exception
     */
    public function apply(string $baseDir)
    {
        $files = $this->findFiles($baseDir);
        $result = $this->checkPolicy($files);
        $keepList = $result['keep'];
        $pruneList = $result['prune'];

        foreach ($keepList as $file) {
            $this->logger->debug("{$file['path']} will be kept for " . implode(', ', $file['reasons']) . ' policies.');
        }

        foreach ($pruneList as $filepath) {
            $this->logger->debug("{$filepath} will be removed.");
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
            foreach ($pruneList as $filepath) {
                if (!$this->pruneFile($filepath)) {
                    throw new RuntimeException("Pruning {$filepath} failed unexpectedly.");
                }
            }
        }

        return $keepList;
    }

    /**
     * check each file for retention
     * only the newest backups for each period will be kept (for daily, the latest backup of the day if there are multiple).
     *
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
        foreach ($files as $info) {
            $filepath = $info['path'];
            $hour = $info['hour'];
            $day = $info['day'];
            $week = $info['week'];
            $month = $info['month'];
            $year = $info['year'];

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
                    $lastList[] = $filepath;
                    $reasons[] = 'last';
                    $keep = true;
                }
            }

            if ($this->keepHourly && !is_null($hour)) {
                $keepCount = count($hourlyList);
                if ($this->keepHourly > $keepCount) {
                    if (!isset($hourlyList[$hourIndex])) {
                        $keep = true;
                        $reasons[] = 'hourly';
                    }
                }
            }

            if ($this->keepDaily && !is_null($day)) {
                $keepCount = count($dailyList);
                if ($this->keepDaily > $keepCount) {
                    if (!isset($dailyList[$dayIndex])) {
                        $keep = true;
                        $reasons[] = 'daily';
                    }
                }
            }

            if ($this->keepWeekly && !is_null($week)) {
                $keepCount = count($weeklyList);
                if ($this->keepWeekly > $keepCount) {
                    if (!isset($weeklyList[$weekIndex])) {
                        $keep = true;
                        $reasons[] = 'weekly';
                    }
                }
            }

            if ($this->keepMonthly && !is_null($month)) {
                $keepCount = count($monthlyList);
                if ($this->keepMonthly > $keepCount) {
                    if (!isset($monthlyList[$monthIndex])) {
                        $keep = true;
                        $reasons[] = 'monthly';
                    }
                }
            }

            if ($this->keepYearly && !is_null($year)) {
                $keepCount = count($yearlyList);
                if ($this->keepYearly > $keepCount) {
                    if (!isset($yearlyList[$yearIndex])) {
                        $keep = true;
                        $reasons[] = 'yearly';
                    }
                }
            }

            // skip this file for all periods
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
                    'path' => $filepath,
                    'reasons' => $reasons,
                ];
            }
            else {
                $pruneList[] = $filepath;
            }
        }

        if (empty($keepList)) {
            // always keep at least 1
            if (count($files) > 0) {
                $keepList[] = [
                    'path' => $files[0]['path'],
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
     * find list of files to apply retention policy.
     *
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
                    $data = $this->checkFile($filepath, $isDirectory);
                    if (is_null($data)) {
                        continue;
                    }
                    $files[] = ['path' => $filepath] + $data;
                }
            }
        }

        // sort files by descending order (from newest to oldest)
        usort($files, function ($a, $b) {
            return $b['time'] - $a['time'];
        });

        return $files;
    }

    /**
     * check file and get time info (UTC time!).
     *
     * @return array
     */
    protected function checkFile(string $filepath, bool $isDirectory = false)
    {
        if (is_callable($this->timeHandler)) {
            $fn = $this->timeHandler;
            $timeData = $fn($filepath, $isDirectory);
            if (is_null($timeData)) {
                return null;
            }
            $year = $timeData['year'] ?? 0;
            $month = $timeData['month'] ?? 0;
            $week = $timeData['week'] ?? 0;
            $day = $timeData['day'] ?? 0;
            $hour = $timeData['hour'] ?? 0;
            $timeCreated = str_pad("{$year}", 4, '0', STR_PAD_LEFT) .
                str_pad("{$month}", 2, '0', STR_PAD_LEFT) .
                str_pad("{$day}", 2, '0', STR_PAD_LEFT) .
                str_pad("{$hour}", 2, '0', STR_PAD_LEFT);
        }
        else {
            $stats = stat($filepath);
            $timeCreated = $stats['mtime'] ?: $stats['ctime'];
            [$year, $month, $week, $day, $hour] = explode('.', date('Y.m.W.d.H', $timeCreated));
        }

        return [
            'time' => (int) $timeCreated,
            'year' => (int) $year,
            'month' => (int) $month,
            'week' => (int) $week, // 1-53 (1 January 2021, Friday = 53)
            'day' => (int) $day, // 1-31
            'hour' => (int) $hour,
            'isDir' => $isDirectory,
        ];
    }

    /**
     * prune action
     * by default try to delete local file.
     *
     * @return bool
     */
    protected function pruneFile($filepath)
    {
        if (is_callable($this->pruneHandler)) {
            $fn = $this->pruneHandler;
            $rs = $fn($filepath);
        }
        else {
            if (is_dir($filepath)) {
                if (substr_count($filepath, '/') < 3) {
                    // basic safeguard for deleting root folders
                    throw new RuntimeException("{$filepath} is not allowed for pruning");
                }
                $directory = new RecursiveDirectoryIterator($filepath);
                $iterator = new RecursiveIteratorIterator($directory);
                foreach ($iterator as $info) {
                    if (!$info->isDir()) {
                        unlink($info->getPathname());
                    }
                }
                $rs = rmdir($filepath);
            }
            else {
                $rs = unlink($filepath);
            }
        }

        return (bool) $rs;
    }

    /**
     * change default prune action.
     */
    public function setPruneHandler(callable $callback)
    {
        $this->pruneHandler = $callback;
    }

    /**
     * change default time calculation.
     */
    public function setTimeHandler(callable $callback)
    {
        $this->timeHandler = $callback;
    }

    /**
     * set finder.
     */
    public function setFindHandler(callable $callback)
    {
        $this->findHandler = $callback;
    }

    /**
     * set regex pattern for directory name to group files so that retention will be applied based on directory name.
     */
    public function setGroupPattern(string $regexPattern)
    {
        $this->groupPattern = $regexPattern;
    }
}
