<?php

declare(strict_types=1);

namespace App\Mapping;

final readonly class CategoryAssignment
{
    public const string SOURCE_ID = 'id';
    public const string SOURCE_NAME = 'name';
    public const string SOURCE_PATTERN = 'pattern';
    public const string SOURCE_NONE = 'none';

    public function __construct(
        public string $key,
        public string $label,
        public int $sort,
        public string $displayName,
        public string $source,
    ) {
    }
}
