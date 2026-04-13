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
     *   'afterWarning'        — non-null if --after field was not found (field appended)
     */
    public static function insertFieldIntoLayout(
        FieldLayout $layout,
        FieldInterface $field,
        ?string $tabName,
        ?string $after,
        ?int $position,
        bool $required,
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

        if ($after) {
            foreach ($elements as $i => $el) {
                $elHandle = null;
                if ($el instanceof CustomField) {
                    $f = $el->getField();
                    $elHandle = $f?->handle;
                } elseif ($el instanceof BaseNativeField) {
                    $elHandle = $el->attribute;
                }
                if ($elHandle === $after) {
                    $insertIndex = $i + 1;
                    break;
                }
            }
            if ($insertIndex === null) {
                $afterWarning = "Field '{$after}' not found in tab '{$targetTab->name}'. Appending to end.";
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
            $after !== null && $afterWarning === null => " after '{$after}'",
            $position !== null                        => " at position {$position}",
            default                                   => ' at the end',
        };

        return [
            'tabName' => $targetTab->name,
            'tabCreated' => $tabCreated,
            'positionDescription' => $posDescription,
            'afterWarning' => $afterWarning,
        ];
    }
}
