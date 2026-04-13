<?php

namespace sustdev\fieldmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use sustdev\fieldmanager\console\ResolvesPositioning;
use sustdev\fieldmanager\helpers\FieldTypeResolver;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage field layouts on entry types from the CLI.
 */
class LayoutController extends Controller
{
    use ResolvesPositioning;

    public ?string $entryType = null;
    public ?string $field = null;
    public ?string $tab = null;
    public ?string $position = null;
    public ?string $after = null;
    public ?string $before = null;
    public bool $required = false;
    public ?string $section = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'show' => array_merge($options, ['entryType']),
            'add-field' => array_merge($options, ['entryType', 'field', 'tab', 'position', 'after', 'before', 'required']),
            'remove-field' => array_merge($options, ['entryType', 'field']),
            'reorder' => array_merge($options, ['entryType', 'field', 'tab', 'after', 'before', 'position']),
            'list-entry-types' => array_merge($options, ['section']),
            default => $options,
        };
    }

    /**
     * List all entry types, optionally filtered by section.
     *
     * Usage:
     *   ddev craft fm/layout/list-entry-types
     *   ddev craft fm/layout/list-entry-types --section=insights
     */
    public function actionListEntryTypes(): int
    {
        if ($this->section) {
            $section = Craft::$app->entries->getSectionByHandle($this->section);
            if (!$section) {
                $this->stderr("Section '{$this->section}' not found.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);
            $this->stdout("Entry types for section '{$section->name}':\n\n", Console::FG_CYAN);
        } else {
            $entryTypes = Craft::$app->entries->getAllEntryTypes();
            $this->stdout("All entry types:\n\n", Console::FG_CYAN);
        }

        if (empty($entryTypes)) {
            $this->stdout("No entry types found.\n");
            return ExitCode::OK;
        }

        $this->stdout(str_pad('Handle', 35) . str_pad('Name', 35) . str_pad('Tabs', 10) . str_pad('Fields', 10) . "UID\n");
        $this->stdout(str_repeat('-', 130) . "\n");

        foreach ($entryTypes as $entryType) {
            $layout = $entryType->getFieldLayout();
            $tabs = $layout ? $layout->getTabs() : [];
            $fieldCount = 0;
            foreach ($tabs as $t) {
                foreach ($t->getElements() as $el) {
                    if ($el instanceof CustomField) {
                        $fieldCount++;
                    }
                }
            }

            $this->stdout(
                str_pad($entryType->handle, 35) .
                str_pad($entryType->name, 35) .
                str_pad((string) count($tabs), 10) .
                str_pad((string) $fieldCount, 10) .
                $entryType->uid . "\n"
            );
        }

        $this->stdout("\nTotal: " . count($entryTypes) . " entry types\n");
        return ExitCode::OK;
    }

    /**
     * List all sections.
     *
     * Usage: ddev craft fm/layout/list-sections
     */
    public function actionListSections(): int
    {
        $sections = Craft::$app->entries->getAllSections();

        if (empty($sections)) {
            $this->stdout("No sections found.\n");
            return ExitCode::OK;
        }

        $this->stdout("All sections:\n\n", Console::FG_CYAN);
        $this->stdout(str_pad('Handle', 30) . str_pad('Name', 30) . str_pad('Type', 15) . str_pad('Entry Types', 15) . "UID\n");
        $this->stdout(str_repeat('-', 130) . "\n");

        foreach ($sections as $section) {
            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);
            $etHandles = array_map(fn($et) => $et->handle, $entryTypes);

            $this->stdout(
                str_pad($section->handle, 30) .
                str_pad($section->name, 30) .
                str_pad(LayoutHelper::sectionTypeValue($section->type), 15) .
                str_pad(implode(', ', $etHandles), 15) .
                $section->uid . "\n"
            );
        }

        $this->stdout("\nTotal: " . count($sections) . " sections\n");
        return ExitCode::OK;
    }

    /**
     * Show the full layout of an entry type (tabs, fields, positions).
     *
     * Usage: ddev craft fm/layout/show --entryType=insight
     */
    public function actionShow(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entryType is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            $this->stdout("Entry type '{$entryType->name}' has no field layout.\n");
            return ExitCode::OK;
        }

        $tabs = $layout->getTabs();

        $this->stdout("Layout for entry type: {$entryType->name} ({$entryType->handle})\n", Console::FG_CYAN);
        $this->stdout("UID: {$entryType->uid}\n\n");

        if (empty($tabs)) {
            $this->stdout("No tabs configured.\n");
            return ExitCode::OK;
        }

        foreach ($tabs as $tabIndex => $tab) {
            $this->stdout("Tab {$tabIndex}: \"{$tab->name}\"\n", Console::FG_YELLOW);
            $this->stdout(str_repeat('-', 80) . "\n");

            $elements = $tab->getElements();
            if (empty($elements)) {
                $this->stdout("  (empty tab)\n");
            }

            foreach ($elements as $elIndex => $element) {
                if ($element instanceof CustomField) {
                    $field = $element->getField();
                    if ($field) {
                        $required = $element->required ? ' [REQUIRED]' : '';
                        $type = FieldTypeResolver::shortLabel(get_class($field));
                        $this->stdout("  [{$elIndex}] {$field->handle} — {$field->name} ({$type}){$required}\n");
                    } else {
                        $this->stdout("  [{$elIndex}] (unknown field - missing reference)\n", Console::FG_RED);
                    }
                } else {
                    $className = FieldTypeResolver::shortLabel(get_class($element));
                    $this->stdout("  [{$elIndex}] <{$className}>\n", Console::FG_GREY);
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Add a field to an entry type's layout.
     *
     * Usage:
     *   ddev craft fm/layout/add-field --entryType=article --field=bodyContent
     *   ddev craft fm/layout/add-field --entryType=article --field=bodyContent --tab=Content --after=title
     *   ddev craft fm/layout/add-field --entryType=article --field=bodyContent --before=footer
     *   ddev craft fm/layout/add-field --entryType=article --field=bodyContent --position=after:title
     *   ddev craft fm/layout/add-field --entryType=article --field=bodyContent --tab="New Tab" --required
     */
    public function actionAddField(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entryType is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->field) {
            $this->stderr("--field is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $positioning = $this->resolvePositioning();
        if ($positioning === null) {
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $field = Craft::$app->fields->getFieldByHandle($this->field);
        if (!$field) {
            $this->stderr("Field '{$this->field}' not found. Create it first with 'ddev craft fm/fields/create'.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            $this->stderr("Entry type '{$entryType->name}' has no field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $existingTab = LayoutHelper::findFieldInLayout($layout, $this->field);
        if ($existingTab !== null) {
            $this->stderr("Field '{$this->field}' is already in tab '{$existingTab}' of entry type '{$entryType->handle}'.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $result = LayoutHelper::insertFieldIntoLayout(
            $layout,
            $field,
            $this->tab,
            $positioning['after'],
            $positioning['position'],
            $this->required,
            $positioning['before'],
        );

        if ($result['afterWarning']) {
            $this->stderr($result['afterWarning'] . "\n", Console::FG_YELLOW);
        }

        if (!Craft::$app->fields->saveLayout($layout)) {
            $this->stderr("Failed to save field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Craft::$app->entries->saveEntryType($entryType)) {
            $this->stderr("Layout saved but failed to re-save entry type.\n", Console::FG_YELLOW);
        }

        $tabStr = $result['tabCreated'] ? ' (new tab created)' : '';
        $reqStr = $this->required ? ' [required]' : '';
        $this->stdout("Field '{$this->field}' added to entry type '{$entryType->handle}' in tab '{$result['tabName']}'{$result['positionDescription']}{$reqStr}{$tabStr}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Move a field to a different position within its tab (or to another tab).
     * Preserves the field's required status.
     *
     * Usage:
     *   ddev craft fm/layout/reorder --entryType=article --field=subtitle --after=title
     *   ddev craft fm/layout/reorder --entryType=article --field=subtitle --before=footer
     *   ddev craft fm/layout/reorder --entryType=article --field=subtitle --position=0
     *   ddev craft fm/layout/reorder --entryType=article --field=subtitle --position=after:title
     *   ddev craft fm/layout/reorder --entryType=article --field=subtitle --tab=Content --after=title
     */
    public function actionReorder(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entryType is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->field) {
            $this->stderr("--field is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $positioning = $this->resolvePositioning();
        if ($positioning === null) {
            return ExitCode::USAGE;
        }

        if ($positioning['after'] === null && $positioning['before'] === null && $positioning['position'] === null) {
            $this->stderr("One of --after, --before, or --position is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            $this->stderr("Entry type '{$entryType->name}' has no field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $extracted = LayoutHelper::extractFieldFromLayout($layout, $this->field);

        if (!$extracted) {
            $this->stderr("Field '{$this->field}' not found in entry type '{$entryType->handle}' layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $field = $extracted['element']->getField();
        $required = $extracted['element']->required;
        $targetTab = $this->tab ?? $extracted['tabName'];

        $result = LayoutHelper::insertFieldIntoLayout(
            $layout,
            $field,
            $targetTab,
            $positioning['after'],
            $positioning['position'],
            $required,
            $positioning['before'],
        );

        if ($result['afterWarning']) {
            $this->stderr($result['afterWarning'] . "\n", Console::FG_YELLOW);
        }

        if (!Craft::$app->fields->saveLayout($layout)) {
            $this->stderr("Failed to save field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Craft::$app->entries->saveEntryType($entryType)) {
            $this->stderr("Layout saved but failed to re-save entry type.\n", Console::FG_YELLOW);
        }

        $tabStr = $result['tabCreated'] ? ' (new tab created)' : '';
        $this->stdout("Field '{$this->field}' moved to tab '{$result['tabName']}'{$result['positionDescription']}{$tabStr}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Remove a field from an entry type's layout.
     *
     * Usage: ddev craft fm/layout/remove-field --entryType=article --field=bodyContent
     */
    public function actionRemoveField(): int
    {
        if (!$this->entryType) {
            $this->stderr("--entryType is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->field) {
            $this->stderr("--field is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            $this->stderr("Entry type '{$entryType->name}' has no field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!LayoutHelper::extractFieldFromLayout($layout, $this->field)) {
            $this->stderr("Field '{$this->field}' not found in entry type '{$entryType->handle}' layout.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!Craft::$app->fields->saveLayout($layout)) {
            $this->stderr("Failed to save field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Craft::$app->entries->saveEntryType($entryType)) {
            $this->stderr("Layout saved but failed to re-save entry type.\n", Console::FG_YELLOW);
        }

        $this->stdout("Field '{$this->field}' removed from entry type '{$entryType->handle}'.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
