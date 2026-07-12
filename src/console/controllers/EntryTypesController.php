<?php

namespace sustdev\fieldmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\enums\Color;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\Console;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage Craft CMS entry types from the CLI.
 *
 * Designed for use by AI agents and developers who need to create or remove
 * entry types without using the control panel. Attaching a newly created entry
 * type to a section or Matrix field is a separate step, see fm/matrix/add-entry-type.
 */
class EntryTypesController extends Controller
{
    public ?string $name = null;
    public ?string $handle = null;
    public bool $hasTitleField = true;
    public ?string $titleFormat = null;
    public bool $showSlugField = false;
    public ?string $icon = null;
    public ?string $color = null;
    public bool $force = false;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'create' => array_merge($options, [
                'name', 'handle', 'hasTitleField', 'titleFormat', 'showSlugField', 'icon', 'color',
            ]),
            'delete' => array_merge($options, ['handle', 'force', 'dryRun']),
            default  => $options,
        };
    }

    /**
     * Create a new entry type.
     *
     * The entry type is created with a minimal field layout (just the Title field,
     * if --has-title-field is not disabled). It is not attached to any section or
     * Matrix field yet, use 'ddev craft fm/matrix/add-entry-type' to attach it to
     * a Matrix field.
     *
     * Usage:
     *   ddev craft fm/entry-types/create --name="Hero Block" --handle=heroBlock
     *   ddev craft fm/entry-types/create --name="Quote" --handle=quote --has-title-field=0 --title-format="{summary}"
     *   ddev craft fm/entry-types/create --name="CTA Block" --handle=ctaBlock --show-slug-field --icon=bullhorn --color=blue
     */
    public function actionCreate(): int
    {
        if (!$this->name) {
            $this->stderr("--name is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->handle) {
            $this->stderr("--handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $existing = Craft::$app->entries->getEntryTypeByHandle($this->handle);
        if ($existing) {
            $this->stderr("An entry type with handle '{$this->handle}' already exists.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $color = null;
        if ($this->color !== null) {
            $color = Color::tryFrom($this->color);
            if ($color === null) {
                $validColors = implode(', ', array_map(fn($case) => $case->value, Color::cases()));
                $this->stderr("Unknown color: '{$this->color}'. Valid values: {$validColors}.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        $entryType = new EntryType([
            'name' => $this->name,
            'handle' => $this->handle,
            'hasTitleField' => $this->hasTitleField,
            'titleFormat' => $this->titleFormat,
            'showSlugField' => $this->showSlugField,
            'icon' => $this->icon,
            'color' => $color,
        ]);

        // The Title field must be present in the layout for hasTitleField to stick.
        // Craft recomputes it from the layout when saving (see Entries::saveEntryType()).
        $fieldLayout = new FieldLayout(['type' => Entry::class]);
        if ($this->hasTitleField) {
            $fieldLayout->setTabs([
                new FieldLayoutTab(['name' => 'Content', 'elements' => [new EntryTitleField()]]),
            ]);
        }
        $entryType->setFieldLayout($fieldLayout);

        if (!Craft::$app->entries->saveEntryType($entryType)) {
            $this->outputEntryTypeErrors($entryType);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Entry type created successfully:\n", Console::FG_GREEN);
        $this->stdout("  Name:   {$entryType->name}\n");
        $this->stdout("  Handle: {$entryType->handle}\n");
        $this->stdout("  UID:    {$entryType->uid}\n");
        $this->stdout("\nThe entry type is now available but not yet attached to any section or Matrix field.\n");
        $this->stdout("Use 'ddev craft fm/matrix/add-entry-type' to attach it to a Matrix field.\n");

        return ExitCode::OK;
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

    private function outputEntryTypeErrors(EntryType $entryType): void
    {
        $errors = $entryType->getErrors();
        $this->stderr("Failed to save entry type:\n", Console::FG_RED);
        if (empty($errors)) {
            $this->stderr("  (no validation errors returned, check Craft logs for details)\n", Console::FG_RED);
        }
        foreach ($errors as $attr => $messages) {
            foreach ($messages as $msg) {
                $this->stderr("  {$attr}: {$msg}\n", Console::FG_RED);
            }
        }
    }
}
