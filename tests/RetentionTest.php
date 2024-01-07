<?php

/*
 * Copyright (c) 2023 Kiva Teknoloji Ltd. All rights reserved.
 *
 * All information contained herein is, and remains the property of Kiva Teknoloji Ltd.
 * The intellectual and technical concepts contained herein are proprietary to Kiva Teknoloji Ltd.
 * and are protected by trade secret or copyright law. Dissemination of this information or
 * reproduction of this material is strictly forbidden unless prior written permission is obtained
 * from Kiva Teknoloji Ltd.
 */

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
            return $a->timestamp > $b->timestamp ? 1 : -1;
        });

        $filesFound = $retention->findFiles($baseDir);
        usort($filesFound, function (FileInfo $a, FileInfo $b) {
            return $a->timestamp > $b->timestamp ? 1 : -1;
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

    private function getStartDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('2021-04-11 01:00');
    }

    public function getFileData(): array
    {
        $startDate = $this->getStartDate();
        $timeData = [];
        for ($i = 1; $i <= 181; ++$i) {
            $dt2 = $startDate->modify("-{$i} day");

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }
        /*for ($i = 0; $i < 5; ++$i) {
            $dt2 = $startDate->modify("-{$i} year");

            $timeData[] = new FileInfo(
                date: $dt2,
                path: "/backup/path/file-" . $dt2->format('Ymd_H')
            );
        }*/

        usort($timeData, function ($a, $b) {
            return $b->timestamp - $a->timestamp ? 1 : 0;
        });

        return $timeData;
    }

    public static function policyProvider(): array
    {
        // first one is policy config, second is list of dates/files to keep
        return [
            [
                [
                    'keep-last' => 2,
                    'keep-daily' => 3,
                    'keep-weekly' => 3,
                    'keep-monthly' => 4,
                    'keep-yearly' => 2,
                ],
                [
                    [
                        'path' => '/backup/path/file-20210410_01',
                        'reasons' => ['last', 'daily', 'weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210409_01',
                        'reasons' => ['last', 'daily'],
                    ],
                    [
                        'path' => '/backup/path/file-20210408_01',
                        'reasons' => ['daily'],
                    ],
                    [
                        'path' => '/backup/path/file-20210404_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210331_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210328_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210228_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20210131_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '/backup/path/file-20201231_01',
                        'reasons' => ['yearly'],
                    ],
                ],
            ],
            [
                [
                    'keep-last' => 3,
                ],
                [
                    [
                        'path' => '/backup/path/file-20210410_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20210409_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '/backup/path/file-20210408_01',
                        'reasons' => ['last'],
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
            //->expects($this->exactly($pruneCount))
            ->method('pruneFile')
            ->willReturn(true)
        ;

        /** @var Retention $retention */
        $retention->setConfig($policy);
        $actualKeepList = $retention->apply('');

        /*usort($actualKeepList, function($a, $b){
            return $a["fileInfo"]->timestamp > $b["fileInfo"]->timestamp ? 1 : -1;
        });*/

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
