<?php

namespace sustdev\fieldmanager\console\controllers;

use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use benf\neo\Plugin as Neo;
use Craft;
use craft\base\FieldInterface;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use sustdev\fieldmanager\console\ResolvesPositioning;
use sustdev\fieldmanager\helpers\FieldTypeResolver;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage Craft CMS fields from the CLI.
 *
 * Designed for use by AI agents and developers who need to quickly create
 * or inspect fields without using the control panel.
 */
class FieldsController extends Controller
{
    use ResolvesPositioning;

    public ?string $name = null;
    public ?string $handle = null;
    public ?string $newHandle = null;
    public ?string $newName = null;
    public ?string $newType = null;
    public ?string $type = null;
    public ?string $instructions = null;
    public bool $required = false;
    public ?string $settings = null;
    public ?string $options = null;
    public ?string $entryType = null;
    public ?string $tab = null;
    public ?string $after = null;
    public ?string $before = null;
    public ?string $position = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'create' => array_merge($options, [
                'name', 'handle', 'type', 'instructions', 'settings', 'options',
            ]),
            'create-and-add' => array_merge($options, [
                'name', 'handle', 'type', 'instructions', 'settings', 'options',
                'entryType', 'tab', 'after', 'before', 'position', 'required',
            ]),
            'show' => array_merge($options, ['handle']),
            'update' => array_merge($options, ['handle', 'newHandle', 'newName', 'newType', 'instructions', 'settings']),
            default => $options,
        };
    }

    /**
     * List all fields in the system.
     *
     * Usage: ddev craft fm/fields/list
     */
    public function actionList(): int
    {
        $fields = Craft::$app->fields->getAllFields();

        if (empty($fields)) {
            $this->stdout("No fields found.\n");
            return ExitCode::OK;
        }

        $this->stdout(str_pad('Handle', 35) . str_pad('Name', 35) . str_pad('Type', 40) . "UID\n");
        $this->stdout(str_repeat('-', 140) . "\n");

        foreach ($fields as $field) {
            $shortType = FieldTypeResolver::shortLabel(get_class($field));
            $this->stdout(
                str_pad($field->handle, 35) .
                str_pad($field->name, 35) .
                str_pad($shortType, 40) .
                $field->uid . "\n"
            );
        }

        $this->stdout("\nTotal: " . count($fields) . " fields\n");
        return ExitCode::OK;
    }

    /**
     * List all available field types (with aliases).
     *
     * Usage: ddev craft fm/fields/types
     */
    public function actionTypes(): int
    {
        $this->stdout("Registered field types:\n\n", Console::FG_CYAN);

        $allTypes = FieldTypeResolver::getAllRegisteredTypes();
        foreach ($allTypes as $type) {
            $label = FieldTypeResolver::shortLabel($type);
            $this->stdout("  " . str_pad($label, 30) . $type . "\n");
        }

        $this->stdout("\nShort aliases you can use with --type:\n\n", Console::FG_CYAN);

        $aliases = FieldTypeResolver::getAvailableAliases();
        $grouped = [];
        foreach ($aliases as $alias => $fqcn) {
            $grouped[$fqcn][] = $alias;
        }

        foreach ($grouped as $fqcn => $aliasList) {
            $label = FieldTypeResolver::shortLabel($fqcn);
            $this->stdout("  " . str_pad($label, 25) . implode(', ', $aliasList) . "\n");
        }

        return ExitCode::OK;
    }

    /**
     * Show details of a specific field.
     *
     * Usage: ddev craft fm/fields/show --handle=myFieldHandle
     */
    public function actionShow(): int
    {
        if (!$this->handle) {
            $this->stderr("--handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $field = Craft::$app->fields->getFieldByHandle($this->handle);
        if (!$field) {
            $this->stderr("Field with handle '{$this->handle}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Field details:\n\n", Console::FG_CYAN);
        $this->stdout("  Name:         {$field->name}\n");
        $this->stdout("  Handle:       {$field->handle}\n");
        $this->stdout("  Type:         " . get_class($field) . "\n");
        $this->stdout("  UID:          {$field->uid}\n");
        $this->stdout("  Instructions: " . ($field->instructions ?: '(none)') . "\n");
        $this->stdout("  Searchable:   " . ($field->searchable ? 'yes' : 'no') . "\n");
        $this->stdout("  Translatable: " . ($field->translationMethod ?? 'none') . "\n");

        $settings = $field->getSettings();
        if (!empty($settings)) {
            $this->stdout("\n  Settings:\n");
            foreach ($settings as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                if (strlen($display) > 80) {
                    $display = substr($display, 0, 77) . '...';
                }
                $this->stdout("    {$key}: {$display}\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Create a new field.
     *
     * Usage:
     *   ddev craft fm/fields/create --name="Body Content" --type=richtext
     *   ddev craft fm/fields/create --name="Price" --type=number --settings='{"decimals":2,"min":0}'
     *   ddev craft fm/fields/create --name="Status" --type=dropdown --options='[{"label":"Draft","value":"draft"}]'
     *   ddev craft fm/fields/create --name="Hero" --type=assets --settings='{"maxRelations":1,"allowedKinds":["image"]}'
     *
     * Handle is auto-generated from name (camelCase) if --handle is omitted.
     */
    public function actionCreate(): int
    {
        if (!$this->name) {
            $this->stderr("--name is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->type) {
            $this->stderr("--type is required. Run 'ddev craft fm/fields/types' to see available types.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $className = FieldTypeResolver::resolve($this->type);
        if (!$className) {
            $this->stderr("Unknown field type: '{$this->type}'. Run 'ddev craft fm/fields/types' to see available types.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $handle = $this->handle ?? StringHelper::toCamelCase(mb_strtolower($this->name));

        $existing = Craft::$app->fields->getFieldByHandle($handle);
        if ($existing) {
            $this->stderr("A field with handle '{$handle}' already exists (type: " . get_class($existing) . ").\n", Console::FG_RED);
            $this->stderr("Use a different --handle or --name.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $settingsArray = $this->parseSettings();
        if ($settingsArray === false) {
            return ExitCode::USAGE;
        }

        $config = FieldTypeResolver::buildConfig($className, $settingsArray);
        $fieldConfig = array_merge($config, [
            'name' => $this->name,
            'handle' => $handle,
        ]);

        if ($this->instructions) {
            $fieldConfig['instructions'] = $this->instructions;
        }

        $field = new $className($fieldConfig);

        $placeholder = $this->prepareNeoField($field);

        if (!Craft::$app->fields->saveField($field)) {
            $this->outputFieldErrors($field);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->cleanupNeoPlaceholder($placeholder);

        $this->stdout("Field created successfully:\n", Console::FG_GREEN);
        $this->stdout("  Name:   {$field->name}\n");
        $this->stdout("  Handle: {$field->handle}\n");
        $this->stdout("  Type:   " . FieldTypeResolver::shortLabel($className) . "\n");
        $this->stdout("  UID:    {$field->uid}\n");
        $this->stdout("\nThe field is now available but not yet added to any layout.\n");
        $this->stdout("Use 'ddev craft fm/layout/add-field' to add it to an entry type.\n");

        return ExitCode::OK;
    }

    /**
     * Create a field AND add it to an entry type's layout in one step.
     *
     * Usage:
     *   ddev craft fm/fields/create-and-add --name="Subtitle" --type=plaintext --entryType=insight
     *   ddev craft fm/fields/create-and-add --name="Body" --type=richtext --entryType=insight --tab=Content --after=title
     *   ddev craft fm/fields/create-and-add --name="CTA" --type=plaintext --entryType=insight --before=footer
     *   ddev craft fm/fields/create-and-add --name="CTA" --type=plaintext --entryType=insight --position=after:title
     *   ddev craft fm/fields/create-and-add --name="Price" --type=number --entryType=product --required --settings='{"decimals":2}'
     */
    public function actionCreateAndAdd(): int
    {
        if (!$this->name) {
            $this->stderr("--name is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->type) {
            $this->stderr("--type is required. Run 'ddev craft fm/fields/types' to see available types.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->entryType) {
            $this->stderr("--entryType is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $positioning = $this->resolvePositioning();
        if ($positioning === null) {
            return ExitCode::USAGE;
        }

        $className = FieldTypeResolver::resolve($this->type);
        if (!$className) {
            $this->stderr("Unknown field type: '{$this->type}'. Run 'ddev craft fm/fields/types' to see available types.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($this->entryType);
        if (!$entryType) {
            $this->stderr("Entry type '{$this->entryType}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $handle = $this->handle ?? StringHelper::toCamelCase(mb_strtolower($this->name));

        // Step 1: Create or reuse existing field
        $field = Craft::$app->fields->getFieldByHandle($handle);

        if ($field) {
            $this->stdout("Field '{$handle}' already exists, reusing it.\n", Console::FG_YELLOW);
            $existingClass = get_class($field);
            if ($existingClass !== $className) {
                $this->stderr("Warning: existing field is type '" . FieldTypeResolver::shortLabel($existingClass) . "', requested type '" . FieldTypeResolver::shortLabel($className) . "' will be ignored.\n", Console::FG_YELLOW);
            }
        } else {
            $settingsArray = $this->parseSettings();
            if ($settingsArray === false) {
                return ExitCode::USAGE;
            }

            $config = FieldTypeResolver::buildConfig($className, $settingsArray);
            $fieldConfig = array_merge($config, [
                'name' => $this->name,
                'handle' => $handle,
            ]);

            if ($this->instructions) {
                $fieldConfig['instructions'] = $this->instructions;
            }

            $field = new $className($fieldConfig);

            $placeholder = $this->prepareNeoField($field);

            if (!Craft::$app->fields->saveField($field)) {
                $this->outputFieldErrors($field);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->cleanupNeoPlaceholder($placeholder);

            $this->stdout("Field created: {$field->name} ({$field->handle}) — " . FieldTypeResolver::shortLabel($className) . "\n", Console::FG_GREEN);
        }

        // Step 2: Add to entry type layout
        return $this->addFieldToLayout($field, $entryType, $positioning);
    }

    private function prepareNeoField(FieldInterface $field): ?BlockType
    {
        if (!$field instanceof NeoField) {
            return null;
        }
        $placeholder = new BlockType([
            'name'      => 'Placeholder',
            'handle'    => 'fmPlaceholder',
            'topLevel'  => true,
            'sortOrder' => 1,
        ]);
        $field->setBlockTypes([$placeholder]);
        return $placeholder;
    }

    private function cleanupNeoPlaceholder(?BlockType $placeholder): void
    {
        if ($placeholder !== null) {
            Neo::getInstance()->blockTypes->delete($placeholder);
        }
    }

    /**
     * Add a field to an entry type layout with tab/position/after/before support.
     *
     * @param array{after: ?string, before: ?string, position: ?int} $positioning
     */
    private function addFieldToLayout($field, $entryType, array $positioning): int
    {
        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            $this->stderr("Entry type '{$entryType->name}' has no field layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $existingTab = LayoutHelper::findFieldInLayout($layout, $field->handle);
        if ($existingTab !== null) {
            $this->stdout("Field '{$field->handle}' is already in tab '{$existingTab}' — no layout change needed.\n", Console::FG_YELLOW);
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
        $this->stdout("Added to layout: entry type '{$entryType->handle}', tab '{$result['tabName']}'{$result['positionDescription']}{$reqStr}{$tabStr}\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Update a field's name, handle and/or type.
     *
     * Changing type will replace the field class — existing content may be lost.
     *
     * Usage:
     *   ddev craft fm/fields/update --handle=oldHandle --new-handle=newHandle
     *   ddev craft fm/fields/update --handle=oldHandle --new-name="New Name"
     *   ddev craft fm/fields/update --handle=oldHandle --new-handle=newHandle --new-name="New Name"
     *   ddev craft fm/fields/update --handle=myField --new-type=richtext
     */
    public function actionUpdate(): int
    {
        if (!$this->handle) {
            $this->stderr("--handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->newHandle && !$this->newName && !$this->newType && !$this->instructions && !$this->settings) {
            $this->stderr("At least one of --new-handle, --new-name, --new-type, --instructions or --settings is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $field = Craft::$app->fields->getFieldByHandle($this->handle);
        if (!$field) {
            $this->stderr("Field with handle '{$this->handle}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->newHandle) {
            $conflict = Craft::$app->fields->getFieldByHandle($this->newHandle);
            if ($conflict && $conflict->id !== $field->id) {
                $this->stderr("A field with handle '{$this->newHandle}' already exists.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $field->handle = $this->newHandle;
        }

        if ($this->newName) {
            $field->name = $this->newName;
        }

        if ($this->newType) {
            $className = FieldTypeResolver::resolve($this->newType);
            if (!$className) {
                $this->stderr("Unknown field type: '{$this->newType}'. Run 'ddev craft fm/fields/types' to see available types.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $this->stderr("Warning: changing field type may result in data loss for existing entries.\n", Console::FG_YELLOW);
            $field = new $className([
                'id' => $field->id,
                'uid' => $field->uid,
                'name' => $field->name,
                'handle' => $field->handle,
                'instructions' => $field->instructions,
            ]);
        }

        if ($this->instructions !== null) {
            $field->instructions = $this->instructions;
        }

        if ($this->settings) {
            $settingsArray = $this->parseSettings();
            if ($settingsArray === false) {
                return ExitCode::USAGE;
            }
            Craft::configure($field, $settingsArray);
        }

        if (!Craft::$app->fields->saveField($field)) {
            $this->outputFieldErrors($field);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Field updated:\n", Console::FG_GREEN);
        $this->stdout("  Name:   {$field->name}\n");
        $this->stdout("  Handle: {$field->handle}\n");
        $this->stdout("  Type:   " . FieldTypeResolver::shortLabel(get_class($field)) . "\n");

        return ExitCode::OK;
    }

    /**
     * Parse --settings and --options JSON flags. Returns false on error.
     */
    private function parseSettings(): array|false
    {
        $settingsArray = [];

        if ($this->settings) {
            $decoded = json_decode($this->settings, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->stderr("Invalid JSON in --settings: " . json_last_error_msg() . "\n", Console::FG_RED);
                return false;
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                $this->stderr("--settings must be a JSON object (e.g. {\"key\":\"value\"}), not an array or scalar.\n", Console::FG_RED);
                return false;
            }
            $settingsArray = $decoded;
        }

        if ($this->options) {
            $decoded = json_decode($this->options, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->stderr("Invalid JSON in --options: " . json_last_error_msg() . "\n", Console::FG_RED);
                return false;
            }
            $settingsArray['options'] = $decoded;
        }

        return $settingsArray;
    }

    private function outputFieldErrors($field, string $message = 'Failed to save field:'): void
    {
        $errors = $field->getErrors();
        $this->stderr("{$message}\n", Console::FG_RED);
        if (empty($errors)) {
            $this->stderr("  (no validation errors returned — check Craft logs for details)\n", Console::FG_RED);
        }
        foreach ($errors as $attr => $messages) {
            foreach ($messages as $msg) {
                $this->stderr("  {$attr}: {$msg}\n", Console::FG_RED);
            }
        }
    }
}
