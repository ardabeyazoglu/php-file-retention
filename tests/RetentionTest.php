<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PhpRetention\FileInfo;
use PhpRetention\Retention;

/**
 * @covers \PhpRetention\Retention
 */
class RetentionTest extends TestCase
{
    private function getStartDate(): DateTimeImmutable
    {
        // let's assume backup files are created at 01:00 and retention check starts right after
        return new DateTimeImmutable('2024-01-22 01:00:00');
    }

    public function testFindFiles()
    {
        $retention = new Retention([]);

        $baseDir = self::$tmpDir . '/findFiles';
        if (!file_exists($baseDir)) {
            mkdir($baseDir, 0o770, true);
        }

        $testData = [
            'test1/file1.txt',
            'test2/file2.txt',
            'test2/file3.txt',
        ];
        $filesExpected = [];
        foreach ($testData as $k => $d) {
            if ($k > 0) {
                sleep(1);
            }
            $dir = $baseDir . '/' . dirname($d);
            if (!file_exists($dir)) {
                mkdir($dir, 0o770, true);
            }
            $filepath = $dir . '/' . basename($d);
            file_put_contents($filepath, time());

            $stats = stat($filepath);
            $timeCreated = $stats['mtime'] ?: $stats['ctime'];

            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $date = $date->setTimestamp($timeCreated);

            $filesExpected[] = new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: false
            );
        }

        // test arrays with same sort order
        usort($filesExpected, function (FileInfo $a, FileInfo $b) {
            return $a->timestamp > $b->timestamp ? -1 : 1;
        });

        $filesFound = $retention->findFiles($baseDir);
        usort($filesFound, function (FileInfo $a, FileInfo $b) {
            return $a->timestamp > $b->timestamp ? -1 : 1;
        });

        foreach ($filesFound as $k => $fileFound) {
            $fileExpected = $filesExpected[$k];
            self::assertEquals(json_encode($fileExpected), json_encode($fileFound), 'Found files did not match!');
        }
    }

    public function testCheckFile()
    {
        $ret = new Retention([]);
        $ret->setTimeHandler(function ($filepath, $isDirectory) {
            $name = basename($filepath);
            if (preg_match('/db\-([0-9]{4})([0-9]{2})([0-9]{2})/', $name, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $day = (int) $matches[3];

                $date = new DateTimeImmutable('now', new DateTimeZone("UTC"));
                $date = $date->setDate($year, $month, $day);
                $date = $date->setTime(0, 0, 0);

                return new FileInfo(
                    date: $date,
                    path: $filepath,
                    isDirectory: $isDirectory
                );
            }

            return null;
        });

        // test valid
        $testPath = '/backup/db/schema/2024/db-20240106.sql.bz2';
        $expectedFileInfo = $this->invokePrivateMethod($ret, 'checkFile', [
            $testPath
        ]);

        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $date->setDate(2024, 01, 06)->setTime(0, 0, 0);
        $actualFileInfo = new FileInfo($date, $testPath, false);
        self::assertEquals($expectedFileInfo, $actualFileInfo);

        // test invalid
        $invalid = $this->invokePrivateMethod($ret, 'checkFile', [
            '/backup/db/schema/2024/db.sql.bz2',
        ]);
        self::assertNull($invalid);
    }

    public function getFileData(): array
    {
        $startDate = $this->getStartDate();
        $timeData = [];
        for ($i = 0; $i <= 366 * 2; ++$i) {
            $dt2 = $startDate->modify("-{$i} day")->setTime(1, 1, 0);

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }
        for ($i = 1; $i < 5; ++$i) {
            $dt2 = $startDate->modify("-{$i} year")->setTime(1, 1, 0);

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }

        usort($timeData, function ($a, $b) {
            return $a->timestamp > $b->timestamp ? -1 : 1;
        });

        return $timeData;
    }

    public static function policyProvider(): array
    {
        // first one is policy config, second is list of dates/files to keep
        return [
            [
                [
                    'keep-last' => 3,
                ],
                [
                    [
                        'path' => '/backup/path/file-20240122_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20240121_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20240120_01',
                        'reasons' => ['last'],
                    ],
                ],
            ],
            [
                [
                    'keep-daily' => 3,
                    'keep-weekly' => 5,
                    'keep-monthly' => 4,
                    'keep-yearly' => 4
                ],
                [
                    [
                        'path' => '/backup/path/file-20240122_01',
                        'reasons' => ['last', 'daily', 'weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240121_01',
                        'reasons' => ['daily', 'weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240120_01',
                        'reasons' => ['daily'],
                    ],
                    [
                        'path' => '/backup/path/file-20240114_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20240107_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231231_01',
                        'reasons' => ['weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231130_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20231031_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20221231_01',
                        'reasons' => ['yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210122_01',
                        'reasons' => ['yearly'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider policyProvider
     *
     * @throws Exception
     */
    public function testApplyRetention(array $policy, array $expectedKeepList)
    {
        $files = $this->getFileData();

        $retention = $this->getMockBuilder(Retention::class)
            ->onlyMethods(['findFiles', 'pruneFile'])
            ->getMock()
        ;

        $retention->expects($this->once())
            ->method('findFiles')
            ->willReturn($files)
        ;

        $fileCount = count($files);
        $keepCount = count($expectedKeepList);
        $pruneCount = $fileCount - $keepCount;

        $retention
            ->expects($this->exactly($pruneCount))
            ->method('pruneFile')
            ->willReturn(true)
        ;

        /** @var Retention $retention */
        $retention->setConfig($policy);
        $actualKeepList = $retention->apply('');

        self::assertSameSize($expectedKeepList, $actualKeepList);

        usort($actualKeepList, function($a, $b){
            return $a["fileInfo"]->timestamp > $b["fileInfo"]->timestamp ? -1 : 1;
        });

        foreach ($expectedKeepList as $i => $expected) {
            $actualKeep = $actualKeepList[$i];
            $actual = [
                "path" => $actualKeep["fileInfo"]->path,
                "reasons" => $actualKeep["reasons"]
            ];
            self::assertEquals($expected, $actual);
        }
    }
}
