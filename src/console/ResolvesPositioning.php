<?php

namespace sustdev\fieldmanager\console;

use craft\helpers\Console;
use sustdev\fieldmanager\helpers\LayoutHelper;

/**
 * Shared helper for console controllers that accept --after, --before and --position flags.
 *
 * The including controller must declare `?string $after`, `?string $before` and
 * `?string $position` properties, and use Yii's `stderr()` method.
 */
trait ResolvesPositioning
{
    /**
     * Resolve --after, --before, --position flags into normalized values.
     *
     * Returns ['after' => ?string, 'before' => ?string, 'position' => ?int] on success,
     * or null on error (after printing the error to stderr).
     */
    private function resolvePositioning(): ?array
    {
        $setFlagCount = (int) ($this->after !== null)
            + (int) ($this->before !== null)
            + (int) ($this->position !== null);

        if ($setFlagCount > 1) {
            $this->stderr("Use only one of --after, --before, or --position.\n", Console::FG_RED);
            return null;
        }

        $parsed = LayoutHelper::parsePositionValue($this->position);
        if ($parsed['error'] !== null) {
            $this->stderr($parsed['error'] . "\n", Console::FG_RED);
            return null;
        }

        return [
            'after' => $this->after ?? $parsed['after'],
            'before' => $this->before ?? $parsed['before'],
            'position' => $parsed['position'],
        ];
    }
}
