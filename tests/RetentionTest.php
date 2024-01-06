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
            $date->setTimestamp($timeCreated);

            $filesExpected[] = new FileInfo(
                date: $date,
                path: $filepath,
                isDirectory: false
            );
        }

        // test arrays with same sort order
        usort($filesExpected, function (FileInfo $a, FileInfo $b) {
            return $a->timestamp > $b->timestamp;
        });

        $filesFound = $retention->findFiles($baseDir);
        usort($filesFound, function (FileInfo $a, FileInfo $b) {
            return $a->timestamp > $b->timestamp;
        });

        foreach ($filesFound as $k => $fileFound) {
            $fileExpected = $filesExpected[$k];
            self::assertEquals($fileExpected, $fileFound, 'Found files did not match!');
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
                $date->setDate($year, $month, $day);
                $date->setTime(0, 0, 0);

                return new FileInfo(
                    date: $date,
                    path: $filepath,
                    isDirectory: $isDirectory
                );
            }

            return null;
        });

        // test valid
        $result = $this->invokePrivateMethod($ret, 'checkFile', [
            '/backup/db/kvacc12345/2024/db-20240106.sql.bz2',
        ]);
        self::assertEquals([
            'time' => 2024010600,
            'year' => 2021,
            'month' => 4,
            'week' => 0,
            'day' => 12,
            'hour' => 0,
            'isDir' => false,
        ], $result);

        // test invalid
        $result = $this->invokePrivateMethod($ret, 'checkFile', [
            '/backup/db/kvacc12345/2021/db.sql.bz2',
        ]);
        self::assertNull($result);
    }

    private function getStartDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('2021-04-10 01:00');
    }

    public function getFileData(): array
    {
        $startDate = $this->getStartDate();
        $timeData = [];
        $dateTimes = [];
        for ($i = 0; $i <= 180; ++$i) {
            $dt2 = $startDate->modify("-{$i} day");
            $dateTimes['T' . $dt2->format('YmdH')] = $dt2;
        }
        for ($i = 0; $i < 5; ++$i) {
            $dt2 = $startDate->modify("-{$i} year");
            $dateTimes['T' . $dt2->format('YmdH')] = $dt2;
        }

        foreach ($dateTimes as $dt) {
            $timeData[] = [
                'path' => $dt->format('Ymd_H'),
                'time' => $dt->getTimestamp(),
                'year' => (int) $dt->format('Y'),
                'month' => (int) $dt->format('m'),
                'week' => (int) $dt->format('W'),
                'day' => (int) $dt->format('d'),
                'hour' => (int) $dt->format('H'),
            ];
        }
        usort($timeData, function ($a, $b) {
            return $b['time'] - $a['time'];
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
                        'path' => '20210410_01',
                        'reasons' => ['last', 'daily', 'weekly', 'monthly', 'yearly'],
                    ],
                    [
                        'path' => '20210409_01',
                        'reasons' => ['last', 'daily'],
                    ],
                    [
                        'path' => '20210408_01',
                        'reasons' => ['daily'],
                    ],
                    [
                        'path' => '20210404_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '20210331_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '20210328_01',
                        'reasons' => ['weekly'],
                    ],
                    [
                        'path' => '20210228_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '20210131_01',
                        'reasons' => ['monthly'],
                    ],
                    [
                        'path' => '20201231_01',
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
                        'path' => '20210410_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '20210409_01',
                        'reasons' => ['last'],
                    ],
                    [
                        'path' => '20210408_01',
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

        $retention->expects($this->exactly($pruneCount))
            ->method('pruneFile')
            ->willReturn(true)
        ;

        /** @var Retention $retention */
        $retention->setConfig($policy);
        $keepList = $retention->apply('');

        self::assertEquals($expectedKeepList, $keepList);
    }
}
