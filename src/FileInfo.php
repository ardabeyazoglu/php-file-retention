<?php

namespace PhpRetention;

use DateTimeImmutable;
use JsonSerializable;
use ReflectionClass;

class FileInfo implements JsonSerializable
{
    public readonly int $timestamp;
    public readonly int $year;
    public readonly int $month;
    public readonly int $week;
    public readonly int $day;
    public readonly int $hour;

    public function __construct(
        public DateTimeImmutable $date,
        public string $path,
        public ?bool $isDirectory = null
    )
    {
        [$year, $month, $week, $day, $hour] = explode('.', $date->format('Y.m.W.d.H'));
        $this->year = (int) $year;
        $this->month = (int) $month;
        $this->week = (int) $week;
        $this->day = (int) $day;
        $this->hour = (int) $hour;
        $this->timestamp = $date->getTimestamp();

        if (is_null($this->isDirectory) && file_exists($this->path)) {
            $this->isDirectory = is_dir($this->path);
        }
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