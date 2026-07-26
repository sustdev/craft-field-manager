<?php

namespace sustdev\fieldmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fields\Matrix as MatrixField;
use craft\helpers\Console;
use craft\models\EntryType;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage Matrix field entry types from the CLI.
 *
 * Designed for use by AI agents and developers who need to attach, detach,
 * and inspect a Matrix field's entry types without using the control panel.
 */
class MatrixController extends Controller
{
    public ?string $field = null;
    public ?string $entryType = null;
    public bool $force = false;
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'show'              => array_merge($options, ['field']),
            'add-entry-type'    => array_merge($options, ['field', 'entryType']),
            'remove-entry-type' => array_merge($options, ['field', 'entryType', 'force', 'dryRun']),
            default             => $options,
        };
    }

    /**
     * Show a Matrix field's settings and attached entry types.
     *
     * Usage: ddev craft fm/matrix/show --field=pageBuilder
     */
    public function actionShow(): int
    {
        $field = $this->requireMatrixField();
        if ($field === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Matrix field: {$field->name} ({$field->handle})\n", Console::FG_CYAN);
        $this->stdout("UID: {$field->uid}\n\n");

        $settings = $field->getSettings();
        unset($settings['entryTypes']);

        if (!empty($settings)) {
            $this->stdout("Settings:\n");
            foreach ($settings as $key => $value) {
                $display = match (true) {
                    is_array($value) => json_encode($value),
                    $value === null => '(none)',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
                if (strlen($display) > 80) {
                    $display = substr($display, 0, 77) . '...';
                }
                $this->stdout("  {$key}: {$display}\n");
            }
            $this->stdout("\n");
        }

        $entryTypes = $field->getEntryTypes();

        if (empty($entryTypes)) {
            $this->stdout("No entry types attached.\n");
            return ExitCode::OK;
        }

        $this->stdout("Entry types:\n", Console::FG_YELLOW);
        foreach ($entryTypes as $entryType) {
            $this->stdout("  - {$entryType->handle} ({$entryType->name}), UID: {$entryType->uid}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Attach an existing entry type to a Matrix field.
     *
     * Usage:
     *   ddev craft fm/matrix/add-entry-type --field=pageBuilder --entry-type=heroBlock
     */
    public function actionAddEntryType(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entry-type is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $field = $this->requireMatrixField();
        if ($field === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entryType = $this->requireEntryType();
        if ($entryType === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entryTypes = $field->getEntryTypes();

        foreach ($entryTypes as $existing) {
            if ($existing->id === $entryType->id) {
                $this->stdout("Entry type '{$entryType->handle}' is already attached to Matrix field '{$field->handle}', skipping.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        $entryTypes[] = $entryType;
        $field->setEntryTypes($entryTypes);

        if (!Craft::$app->fields->saveField($field)) {
            $this->outputFieldErrors($field);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Entry type '{$entryType->handle}' attached to Matrix field '{$field->handle}'.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Detach an entry type from a Matrix field.
     *
     * Refuses by default if the field still has saved entries of this type, since
     * those entries would become inaccessible. Use --force to detach anyway, or
     * --dry-run to preview without making changes.
     *
     * Usage:
     *   ddev craft fm/matrix/remove-entry-type --field=pageBuilder --entry-type=heroBlock
     *   ddev craft fm/matrix/remove-entry-type --field=pageBuilder --entry-type=heroBlock --dry-run
     *   ddev craft fm/matrix/remove-entry-type --field=pageBuilder --entry-type=heroBlock --force
     */
    public function actionRemoveEntryType(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entry-type is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $field = $this->requireMatrixField();
        if ($field === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entryType = $this->requireEntryType();
        if ($entryType === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entryTypes = $field->getEntryTypes();
        $remaining = array_values(array_filter(
            $entryTypes,
            fn(EntryType $et) => $et->id !== $entryType->id,
        ));

        if (count($remaining) === count($entryTypes)) {
            $this->stdout("Entry type '{$entryType->handle}' is not attached to Matrix field '{$field->handle}', no change needed.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (empty($remaining)) {
            $this->stderr("Refusing to detach: Matrix fields require at least one entry type.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $hasContent = LayoutHelper::matrixFieldHasEntriesOfType($field, $entryType);

        if ($hasContent) {
            $this->stdout("Matrix field '{$field->handle}' has existing entries of type '{$entryType->handle}'.\n", Console::FG_YELLOW);
        } else {
            $this->stdout("Matrix field '{$field->handle}' has no existing entries of type '{$entryType->handle}'.\n");
        }

        if ($this->dryRun) {
            $this->stdout("[dry-run] Would detach entry type '{$entryType->handle}' from Matrix field '{$field->handle}'.\n", Console::FG_CYAN);
            return ExitCode::OK;
        }

        if ($hasContent && !$this->force) {
            $this->stderr("Refusing to detach: existing entries of this type would become inaccessible. Re-run with --force to detach anyway.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $field->setEntryTypes($remaining);

        if (!Craft::$app->fields->saveField($field)) {
            $this->outputFieldErrors($field);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Entry type '{$entryType->handle}' detached from Matrix field '{$field->handle}'.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function requireMatrixField(): ?MatrixField
    {
        if (!$this->field) {
            $this->stderr("--field is required.\n", Console::FG_RED);
            return null;
        }

        $field = Craft::$app->fields->getFieldByHandle($this->field);
        if (!$field instanceof MatrixField) {
            $this->stderr("Matrix field '{$this->field}' not found.\n", Console::FG_RED);
            return null;
        }

        return $field;
    }

    private function requireEntryType(): ?EntryType
    {
        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return null;
        }

        return $entryType;
    }

    private function outputFieldErrors(MatrixField $field): void
    {
        $errors = $field->getErrors();
        $this->stderr("Failed to save Matrix field:\n", Console::FG_RED);
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
