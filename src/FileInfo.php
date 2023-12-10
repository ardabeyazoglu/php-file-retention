<?php

namespace PhpRetention;

use JsonSerializable;
use ReflectionClass;

readonly class FileInfo implements JsonSerializable
{

    public function __construct(
        public int $timestampInUTC,
        public int $year,
        public int $month,
        /** @var int $week 1-53 (1 January 2021, Friday = 53) */
        public int $week,
        /** @var int $day day of month */
        public int $day,
        public int $hour,
        public string $path,
        public bool $isDirectory = false
    )
    {
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
            if ($prop->isPublic()) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }
        return $data;
    }
}