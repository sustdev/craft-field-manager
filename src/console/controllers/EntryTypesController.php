<?php

namespace sustdev\fieldmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage Craft CMS entry types from the CLI.
 *
 * Currently only supports deletion. Entry type creation is intentionally
 * out of scope — entry types should be created via the control panel or
 * project config, since they involve structural decisions (sections, Matrix
 * ownership) that don't fit a one-shot CLI call.
 */
class EntryTypesController extends Controller
{
    public ?string $handle = null;
    public bool $force = false;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'delete' => array_merge($options, ['handle', 'force', 'dryRun']),
            default  => $options,
        };
    }

    /**
     * Delete an entry type by handle.
     *
     * Refuses by default if the entry type is still used in any section or Matrix field.
     * Use --force to delete anyway, or --dry-run to preview without making changes.
     *
     * Usage:
     *   ddev craft fm/entry-types/delete --handle=oldBlock
     *   ddev craft fm/entry-types/delete --handle=oldBlock --dry-run
     *   ddev craft fm/entry-types/delete --handle=oldBlock --force
     */
    public function actionDelete(): int
    {
        if (!$this->handle) {
            $this->stderr("--handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->handle);
        if (!$entryType) {
            $this->stderr("Entry type with handle '{$this->handle}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $usages = LayoutHelper::findEntryTypeUsages($entryType);
        $hasUsages = LayoutHelper::hasEntryTypeUsages($usages);

        if ($hasUsages) {
            $this->stdout("Entry type '{$entryType->handle}' is still in use:\n", Console::FG_YELLOW);
            foreach ($usages['sections'] as $usage) {
                $this->stdout("  - section '{$usage['handle']}' ({$usage['name']})\n");
            }
            foreach ($usages['matrixFields'] as $usage) {
                $this->stdout("  - Matrix field '{$usage['handle']}' ({$usage['name']})\n");
            }
            $this->stdout("\n");
        } else {
            $this->stdout("Entry type '{$entryType->handle}' is not used anywhere.\n");
        }

        if ($this->dryRun) {
            $this->stdout("[dry-run] Would delete entry type '{$entryType->handle}' ({$entryType->name}).\n", Console::FG_CYAN);
            return ExitCode::OK;
        }

        if ($hasUsages && !$this->force) {
            $this->stderr("Refusing to delete: remove the entry type from the sections/fields above or re-run with --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Craft::$app->entries->deleteEntryType($entryType)) {
            $this->stderr("Failed to delete entry type '{$entryType->handle}'.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Entry type '{$entryType->handle}' deleted.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
