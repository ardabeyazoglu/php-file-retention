<?php

namespace PhpRetention;

use DateTimeImmutable;
use JsonSerializable;
use ReflectionClass;

readonly class FileInfo implements JsonSerializable
{

    public int $timestamp;
    public int $year;
    public int $month;
    public int $week;
    public int $day;
    public int $hour;

    public function __construct(
        public DateTimeImmutable $date,
        public string $path,
        public bool $isDirectory = false
    )
    {
        [$year, $month, $week, $day, $hour] = explode('.', $date->format('Y.m.W.d.H'));
        $this->year = (int) $year;
        $this->month = (int) $month;
        $this->week = (int) $week;
        $this->day = (int) $day;
        $this->hour = (int) $hour;
        $this->timestamp = $date->getTimestamp();
    }

    public function __toString()
    {
        return $this->path;
    }

    public function jsonSerialize(): array
    {
        $reflection = new ReflectionClass($this);
        $props = $reflection->getProperties();
        $data = [];
        foreach ($props as $prop) {
            if ($prop->getName() === 'date') {
                continue;
            }
            if ($prop->isPublic()) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }
        return $data;
    }
}