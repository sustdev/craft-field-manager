<?php

namespace sustdev\fieldmanager\console\controllers;

use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use benf\neo\Plugin as Neo;
use Craft;
use craft\console\Controller;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use sustdev\fieldmanager\console\ResolvesPositioning;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Manage Neo field block types and their layouts from the CLI.
 *
 * Designed for use by AI agents and developers who need to quickly build
 * Neo field structures without using the control panel.
 */
class NeoController extends Controller
{
    use ResolvesPositioning;

    public ?string $field = null;
    public ?string $block = null;
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $newName = null;
    public ?string $newHandle = null;
    public bool $topLevel = true;
    public ?string $fieldHandle = null;
    public ?string $tab = null;
    public ?string $after = null;
    public ?string $before = null;
    public ?string $position = null;
    public bool $required = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'show'         => array_merge($options, ['field']),
            'add-block'    => array_merge($options, ['field', 'name', 'handle', 'topLevel']),
            'add-field'    => array_merge($options, ['field', 'block', 'fieldHandle', 'tab', 'after', 'before', 'position', 'required']),
            'remove-field' => array_merge($options, ['field', 'block', 'fieldHandle']),
            'update-block' => array_merge($options, ['field', 'block', 'newName', 'newHandle']),
            default        => $options,
        };
    }

    /**
     * Show a Neo field's block types and their field layouts.
     *
     * Usage: ddev craft fm/neo/show --field=wfdLandingPageComponents
     */
    public function actionShow(): int
    {
        $neoField = $this->requireNeoField();
        if ($neoField === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $blockTypes = Neo::getInstance()->blockTypes->getByFieldId($neoField->id);

        if (empty($blockTypes)) {
            $this->stdout("Neo field '{$this->field}' has no block types yet.\n");
            return ExitCode::OK;
        }

        $this->stdout("Neo field: {$neoField->name} ({$neoField->handle})\n\n", Console::FG_CYAN);

        foreach ($blockTypes as $blockType) {
            $topLevelStr = $blockType->topLevel ? ' [top-level]' : '';
            $this->stdout("  Block: {$blockType->name} ({$blockType->handle}){$topLevelStr}\n", Console::FG_GREEN);

            $tabs = $blockType->getFieldLayout()->getTabs();

            if (empty($tabs)) {
                $this->stdout("    (no fields)\n");
                continue;
            }

            foreach ($tabs as $tab) {
                $this->stdout("    Tab: {$tab->name}\n");
                foreach ($tab->getElements() as $el) {
                    if ($el instanceof CustomField) {
                        $f = $el->getField();
                        if ($f) {
                            $req = $el->required ? ' *' : '';
                            $this->stdout("      - {$f->handle} ({$f->name}){$req}\n");
                        }
                    }
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Add a block type to a Neo field.
     *
     * Usage:
     *   ddev craft fm/neo/add-block --field=wfdLandingPageComponents --name="Hero Banner"
     *   ddev craft fm/neo/add-block --field=wfdLandingPageComponents --name="Hero Banner" --handle=heroBanner --top-level=1
     */
    public function actionAddBlock(): int
    {
        if (!$this->name) {
            $this->stderr("--name is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $neoField = $this->requireNeoField();
        if ($neoField === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $blockHandle = $this->handle ?? StringHelper::toCamelCase(mb_strtolower($this->name));

        $existing = Neo::getInstance()->blockTypes->getByFieldId($neoField->id);
        foreach ($existing as $bt) {
            if ($bt->handle === $blockHandle) {
                $this->stdout("Block type '{$blockHandle}' already exists in field '{$this->field}' — skipping.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        $blockType = new BlockType([
            'fieldId'   => $neoField->id,
            'name'      => $this->name,
            'handle'    => $blockHandle,
            'topLevel'  => $this->topLevel,
            'sortOrder' => count($existing) + 1,
        ]);

        if (!Neo::getInstance()->blockTypes->save($blockType)) {
            $this->stderr("Failed to save block type '{$blockHandle}'.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Block type created: {$blockType->name} ({$blockType->handle}) in field '{$neoField->handle}'\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Add a Craft field to a Neo block type's layout.
     *
     * Usage:
     *   ddev craft fm/neo/add-field --field=wfdLandingPageComponents --block=heroBanner --field-handle=title
     *   ddev craft fm/neo/add-field --field=wfdLandingPageComponents --block=heroBanner --field-handle=subtitle --tab=Content --after=title
     *   ddev craft fm/neo/add-field --field=wfdLandingPageComponents --block=heroBanner --field-handle=cta --before=footer
     *   ddev craft fm/neo/add-field --field=wfdLandingPageComponents --block=heroBanner --field-handle=cta --position=after:title
     *   ddev craft fm/neo/add-field --field=wfdLandingPageComponents --block=heroBanner --field-handle=body --required
     */
    public function actionAddField(): int
    {
        if (!$this->block) {
            $this->stderr("--block is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!$this->fieldHandle) {
            $this->stderr("--field-handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $positioning = $this->resolvePositioning();
        if ($positioning === null) {
            return ExitCode::USAGE;
        }

        $blockType = $this->requireBlockType();
        if ($blockType === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $craftField = Craft::$app->fields->getFieldByHandle($this->fieldHandle);
        if (!$craftField) {
            $this->stderr("Field with handle '{$this->fieldHandle}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $blockType->getFieldLayout();

        $existingTab = LayoutHelper::findFieldInLayout($layout, $this->fieldHandle);
        if ($existingTab !== null) {
            $this->stdout("Field '{$this->fieldHandle}' is already in tab '{$existingTab}' of block '{$this->block}' — no change needed.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $result = LayoutHelper::insertFieldIntoLayout(
            $layout,
            $craftField,
            $this->tab,
            $positioning['after'],
            $positioning['position'],
            $this->required,
            $positioning['before'],
        );

        if ($result['afterWarning']) {
            $this->stderr($result['afterWarning'] . "\n", Console::FG_YELLOW);
        }

        if (!Neo::getInstance()->blockTypes->save($blockType)) {
            $this->stderr("Failed to save block type layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $tabStr = $result['tabCreated'] ? ' (new tab created)' : '';
        $reqStr = $this->required ? ' [required]' : '';
        $this->stdout(
            "Added '{$this->fieldHandle}' to block '{$blockType->handle}', tab '{$result['tabName']}'" .
            "{$result['positionDescription']}{$reqStr}{$tabStr}\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * Remove a Craft field from a Neo block type's layout.
     *
     * Usage:
     *   ddev craft fm/neo/remove-field --field=wfdLandingPageComponents --block=ctaBlock --field-handle=ctaLabel
     */
    public function actionRemoveField(): int
    {
        if (!$this->block) {
            $this->stderr("--block is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!$this->fieldHandle) {
            $this->stderr("--field-handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $blockType = $this->requireBlockType();
        if ($blockType === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $layout = $blockType->getFieldLayout();
        $result = LayoutHelper::extractFieldFromLayout($layout, $this->fieldHandle);

        if ($result === null) {
            $this->stdout("Field '{$this->fieldHandle}' not found in block '{$this->block}' — no change needed.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!Neo::getInstance()->blockTypes->save($blockType)) {
            $this->stderr("Failed to save block type layout.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Removed '{$this->fieldHandle}' from block '{$blockType->handle}' (was in tab '{$result['tabName']}')\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Update a Neo block type's name and/or handle.
     *
     * Usage:
     *   ddev craft fm/neo/update-block --field=wfdLandingPageComponents --block=textBlock --new-name="Introduction" --new-handle=introduction
     */
    public function actionUpdateBlock(): int
    {
        if (!$this->block) {
            $this->stderr("--block is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!$this->newName && !$this->newHandle) {
            $this->stderr("At least one of --new-name or --new-handle is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $blockType = $this->requireBlockType();
        if ($blockType === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->newName) {
            $blockType->name = $this->newName;
        }
        if ($this->newHandle) {
            $blockType->handle = $this->newHandle;
        }

        if (!Neo::getInstance()->blockTypes->save($blockType)) {
            $this->stderr("Failed to save block type.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Block type updated: {$blockType->name} ({$blockType->handle})\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    private function requireBlockType(): ?BlockType
    {
        $neoField = $this->requireNeoField();
        if ($neoField === null) {
            return null;
        }

        $blockType = $this->resolveBlockType($neoField->id, $this->block);
        if (!$blockType) {
            $this->stderr("Block type '{$this->block}' not found in Neo field '{$this->field}'.\n", Console::FG_RED);
            return null;
        }

        return $blockType;
    }

    private function requireNeoField(): ?NeoField
    {
        if (!$this->field) {
            $this->stderr("--field is required.\n", Console::FG_RED);
            return null;
        }

        $field = Craft::$app->fields->getFieldByHandle($this->field);
        if (!$field instanceof NeoField) {
            $this->stderr("Neo field '{$this->field}' not found.\n", Console::FG_RED);
            return null;
        }

        return $field;
    }

    private function resolveBlockType(int $fieldId, string $handle): ?BlockType
    {
        foreach (Neo::getInstance()->blockTypes->getByFieldId($fieldId) as $bt) {
            if ($bt->handle === $handle) {
                return $bt;
            }
        }
        return null;
    }
}
