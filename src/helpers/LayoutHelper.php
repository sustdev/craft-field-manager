<?php

namespace sustdev\fieldmanager\helpers;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

class LayoutHelper
{
    /**
     * Resolve a section type to its string value, handling both PHP enums and legacy strings.
     */
    public static function sectionTypeValue(mixed $type): string
    {
        return is_string($type) ? $type : $type->value;
    }

    /**
     * Extract a CustomField element from a layout by field handle, removing it from its tab.
     * Returns ['element' => CustomField, 'tabName' => string], or null if not found.
     */
    public static function extractFieldFromLayout(FieldLayout $layout, string $fieldHandle): ?array
    {
        foreach ($layout->getTabs() as $tab) {
            $elements = $tab->getElements();
            $filtered = [];
            $found = null;
            foreach ($elements as $element) {
                if ($element instanceof CustomField) {
                    $f = $element->getField();
                    if ($f && $f->handle === $fieldHandle) {
                        $found = $element;
                        continue;
                    }
                }
                $filtered[] = $element;
            }
            if ($found) {
                $tab->setElements($filtered);
                return ['element' => $found, 'tabName' => $tab->name];
            }
        }
        return null;
    }

    /**
     * Check if a field already exists in any tab of a layout.
     * Returns the tab name if found, null otherwise.
     */
    public static function findFieldInLayout(FieldLayout $layout, string $fieldHandle): ?string
    {
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if ($element instanceof CustomField) {
                    $f = $element->getField();
                    if ($f && $f->handle === $fieldHandle) {
                        return $tab->name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Insert a field into a layout at the specified tab/position.
     * Mutates $layout directly (calls setTabs).
     *
     * Returns:
     *   'tabName'             — name of the tab the field was inserted into
     *   'tabCreated'          — whether a new tab was created
     *   'positionDescription' — human-readable position string for output
     *   'afterWarning'        — non-null if --after/--before field was not found (field appended)
     */
    public static function insertFieldIntoLayout(
        FieldLayout $layout,
        FieldInterface $field,
        ?string $tabName,
        ?string $after,
        ?int $position,
        bool $required,
        ?string $before = null,
    ): array {
        $tabs = $layout->getTabs();
        $targetTab = null;
        $tabCreated = false;

        if ($tabName) {
            foreach ($tabs as $t) {
                if (strcasecmp($t->name, $tabName) === 0) {
                    $targetTab = $t;
                    break;
                }
            }
            if (!$targetTab) {
                $targetTab = new FieldLayoutTab(['name' => $tabName, 'elements' => []]);
                $tabs[] = $targetTab;
                $tabCreated = true;
            }
        } else {
            $targetTab = $tabs[0] ?? null;
            if (!$targetTab) {
                $targetTab = new FieldLayoutTab(['name' => 'Content', 'elements' => []]);
                $tabs[] = $targetTab;
                $tabCreated = true;
            }
        }

        $customField = new CustomField($field, ['required' => $required]);
        $elements = $targetTab->getElements();
        $insertIndex = null;
        $afterWarning = null;

        if ($after !== null) {
            $foundAt = self::findElementIndexByHandle($elements, $after);
            if ($foundAt !== null) {
                $insertIndex = $foundAt + 1;
            } else {
                $afterWarning = "Field '{$after}' not found in tab '{$targetTab->name}'. Appending to end.";
            }
        } elseif ($before !== null) {
            $foundAt = self::findElementIndexByHandle($elements, $before);
            if ($foundAt !== null) {
                $insertIndex = $foundAt;
            } else {
                $afterWarning = "Field '{$before}' not found in tab '{$targetTab->name}'. Appending to end.";
            }
        } elseif ($position !== null && $position >= 0 && $position <= count($elements)) {
            $insertIndex = $position;
        }

        if ($insertIndex !== null) {
            array_splice($elements, $insertIndex, 0, [$customField]);
        } else {
            $elements[] = $customField;
        }

        $layout->setTabs($tabs);
        $targetTab->setElements($elements);

        $posDescription = match (true) {
            $after !== null && $afterWarning === null   => " after '{$after}'",
            $before !== null && $afterWarning === null  => " before '{$before}'",
            $position !== null                          => " at position {$position}",
            default                                     => ' at the end',
        };

        return [
            'tabName' => $targetTab->name,
            'tabCreated' => $tabCreated,
            'positionDescription' => $posDescription,
            'afterWarning' => $afterWarning,
        ];
    }

    /**
     * Locate a layout element by its handle. Returns the index, or null if not found.
     */
    private static function findElementIndexByHandle(array $elements, string $handle): ?int
    {
        foreach ($elements as $i => $el) {
            $elHandle = null;
            if ($el instanceof CustomField) {
                $f = $el->getField();
                $elHandle = $f?->handle;
            } elseif ($el instanceof BaseNativeField) {
                $elHandle = $el->attribute;
            }
            if ($elHandle === $handle) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Parse a --position value. Returns a normalized array:
     *   ['after' => string|null, 'before' => string|null, 'position' => int|null, 'error' => string|null]
     *
     * Accepted forms:
     *   - "<int>"            → numeric position (backwards compatible)
     *   - "after:<handle>"   → insert after field
     *   - "before:<handle>"  → insert before field
     */
    public static function parsePositionValue(?string $value): array
    {
        $result = ['after' => null, 'before' => null, 'position' => null, 'error' => null];

        if ($value === null || $value === '') {
            return $result;
        }

        if (ctype_digit($value)) {
            $result['position'] = (int) $value;
            return $result;
        }

        if (str_starts_with($value, 'after:')) {
            $handle = substr($value, 6);
            if ($handle === '') {
                $result['error'] = "--position=after: requires a field handle (e.g. --position=after:title).";
                return $result;
            }
            $result['after'] = $handle;
            return $result;
        }

        if (str_starts_with($value, 'before:')) {
            $handle = substr($value, 7);
            if ($handle === '') {
                $result['error'] = "--position=before: requires a field handle (e.g. --position=before:title).";
                return $result;
            }
            $result['before'] = $handle;
            return $result;
        }

        $result['error'] = "Invalid --position value '{$value}'. Expected an integer, 'after:<handle>', or 'before:<handle>'.";
        return $result;
    }

    /**
     * Find all places where a field is currently used.
     *
     * Scans:
     *   - All entry-type field layouts
     *   - All Neo block type layouts (if Neo is installed)
     *
     * Returns:
     *   [
     *     'entryTypes' => [['handle' => string, 'name' => string, 'tab' => string], ...],
     *     'neoBlocks'  => [['fieldHandle' => string, 'blockHandle' => string, 'blockName' => string, 'tab' => string], ...],
     *   ]
     */
    public static function findFieldUsages(FieldInterface $field): array
    {
        $usages = [
            'entryTypes' => [],
            'neoBlocks'  => [],
        ];

        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $layout = $entryType->getFieldLayout();
            if (!$layout) {
                continue;
            }
            $tabName = self::findFieldInLayout($layout, $field->handle);
            if ($tabName !== null) {
                $usages['entryTypes'][] = [
                    'handle' => $entryType->handle,
                    'name'   => $entryType->name,
                    'tab'    => $tabName,
                ];
            }
        }

        if (class_exists(\benf\neo\Plugin::class)) {
            $neoPlugin = \benf\neo\Plugin::getInstance();
            if ($neoPlugin !== null) {
                foreach (Craft::$app->fields->getAllFields() as $f) {
                    if (!$f instanceof \benf\neo\Field) {
                        continue;
                    }
                    $blockTypes = $neoPlugin->blockTypes->getByFieldId($f->id);
                    foreach ($blockTypes as $blockType) {
                        $layout = $blockType->getFieldLayout();
                        if (!$layout) {
                            continue;
                        }
                        $tabName = self::findFieldInLayout($layout, $field->handle);
                        if ($tabName !== null) {
                            $usages['neoBlocks'][] = [
                                'fieldHandle' => $f->handle,
                                'blockHandle' => $blockType->handle,
                                'blockName'   => $blockType->name,
                                'tab'         => $tabName,
                            ];
                        }
                    }
                }
            }
        }

        return $usages;
    }

    /**
     * Find all places where an entry type is currently used.
     *
     * Scans:
     *   - All sections' entry type assignments
     *   - All Matrix fields' `entryTypes` setting
     *
     * Returns:
     *   [
     *     'sections'     => [['handle' => string, 'name' => string], ...],
     *     'matrixFields' => [['handle' => string, 'name' => string], ...],
     *   ]
     */
    public static function findEntryTypeUsages(EntryType $entryType): array
    {
        $usages = [
            'sections'     => [],
            'matrixFields' => [],
        ];

        foreach (Craft::$app->entries->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $et) {
                if ($et->id === $entryType->id) {
                    $usages['sections'][] = [
                        'handle' => $section->handle,
                        'name'   => $section->name,
                    ];
                    break;
                }
            }
        }

        foreach (Craft::$app->fields->getAllFields() as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }
            foreach ($field->getEntryTypes() as $et) {
                if ($et->id === $entryType->id) {
                    $usages['matrixFields'][] = [
                        'handle' => $field->handle,
                        'name'   => $field->name,
                    ];
                    break;
                }
            }
        }

        return $usages;
    }

    /**
     * Whether a field-usage result contains any usages.
     */
    public static function hasFieldUsages(array $usages): bool
    {
        return !empty($usages['entryTypes']) || !empty($usages['neoBlocks']);
    }

    /**
     * Whether an entry-type-usage result contains any usages.
     */
    public static function hasEntryTypeUsages(array $usages): bool
    {
        return !empty($usages['sections']) || !empty($usages['matrixFields']);
    }

    /**
     * Whether a Matrix field currently has any saved entries of the given entry type.
     *
     * Checks across all sites and statuses (including drafts), but not trashed entries.
     */
    public static function matrixFieldHasEntriesOfType(Matrix $field, EntryType $entryType): bool
    {
        return Entry::find()
            ->fieldId($field->id)
            ->typeId($entryType->id)
            ->site('*')
            ->status(null)
            ->drafts(null)
            ->limit(1)
            ->exists();
    }
}
