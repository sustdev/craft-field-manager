<?php

namespace sustdev\fieldmanager\helpers;

use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fieldlayoutelements\CustomField;
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
}
