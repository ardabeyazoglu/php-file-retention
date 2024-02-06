<?php

namespace PhpRetention;

use DateTimeImmutable;
use JsonSerializable;
use ReflectionClass;

class Result implements JsonSerializable
{
    public function __construct(
        public readonly array $keepList,
        public readonly array $pruneList,
        public readonly int $startTime,
        public readonly int $endTime
    )
    {}

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