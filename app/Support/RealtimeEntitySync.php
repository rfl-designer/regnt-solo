<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class RealtimeEntitySync
{
    public const ENTITY_PROJECT = 'project';

    public const ENTITY_FEATURE = 'feature';

    public const ENTITY_TASK = 'task';

    public const ENTITY_DOCUMENT = 'document';

    public const ENTITY_ISSUE = 'issue';

    /**
     * @return list<string>
     */
    public static function trackedEntities(): array
    {
        return [
            self::ENTITY_PROJECT,
            self::ENTITY_FEATURE,
            self::ENTITY_TASK,
            self::ENTITY_DOCUMENT,
            self::ENTITY_ISSUE,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function snapshot(): array
    {
        $snapshot = [];

        foreach (self::trackedEntities() as $entity) {
            $snapshot[$entity] = (int) Cache::get(self::cacheKey($entity), 0);
        }

        return $snapshot;
    }

    public static function touch(string $entity): void
    {
        if (! in_array($entity, self::trackedEntities(), true)) {
            throw new InvalidArgumentException("Unsupported realtime entity [{$entity}].");
        }

        $cacheKey = self::cacheKey($entity);

        if (! Cache::has($cacheKey)) {
            Cache::forever($cacheKey, 1);

            return;
        }

        Cache::increment($cacheKey);
    }

    private static function cacheKey(string $entity): string
    {
        return "realtime-entity-sync:{$entity}";
    }
}
