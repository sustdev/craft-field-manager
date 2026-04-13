<?php

namespace sustdev\fieldmanager\helpers;

use Craft;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Time;
use craft\fields\Url;
use craft\fields\Users;

class FieldTypeResolver
{
    /**
     * Map of short aliases to fully qualified field class names.
     * Keys are lowercase for case-insensitive lookup.
     */
    private static array $aliasMap = [
        // Core text
        'plaintext'    => PlainText::class,
        'plain-text'   => PlainText::class,
        'text'         => PlainText::class,
        'email'        => Email::class,
        'url'          => Url::class,
        'color'        => Color::class,
        'colour'       => Color::class,

        // Numbers
        'number'       => Number::class,
        'range'        => Range::class,
        'money'        => Money::class,

        // Date/time
        'date'         => Date::class,
        'datetime'     => Date::class,
        'time'         => Time::class,

        // Boolean / selection
        'lightswitch'  => Lightswitch::class,
        'boolean'      => Lightswitch::class,
        'toggle'       => Lightswitch::class,
        'dropdown'     => Dropdown::class,
        'select'       => Dropdown::class,
        'checkboxes'   => Checkboxes::class,
        'radio'        => RadioButtons::class,
        'radiobuttons' => RadioButtons::class,
        'multiselect'  => MultiSelect::class,

        // Relational
        'entries'      => Entries::class,
        'assets'       => Assets::class,
        'categories'   => Categories::class,
        'tags'         => Tags::class,
        'users'        => Users::class,

        // Misc
        'table'        => Table::class,
        'country'      => Country::class,

        // Neo (if installed)
        'neo'          => 'benf\\neo\\Field',

        // Rich text — Redactor
        'richtext'     => 'craft\\redactor\\Field',
        'rich-text'    => 'craft\\redactor\\Field',
        'redactor'     => 'craft\\redactor\\Field',

        // CKEditor (if installed)
        'ckeditor'     => 'craft\\ckeditor\\Field',

        // WYSIWYG resolves to whichever rich text editor is installed
        'wysiwyg'      => 'craft\\redactor\\Field',
    ];

    /**
     * Resolve a type alias or FQCN to a field class name.
     * Returns null if not found or if the class doesn't exist (plugin not installed).
     */
    public static function resolve(string $type): ?string
    {
        $lower = strtolower($type);

        if (isset(self::$aliasMap[$lower])) {
            $className = self::$aliasMap[$lower];
            if (class_exists($className)) {
                return $className;
            }

            // Fallback to CKEditor if Redactor is not installed
            if (in_array($lower, ['wysiwyg', 'richtext', 'rich-text', 'redactor'], true)) {
                if (class_exists('craft\\ckeditor\\Field')) {
                    return 'craft\\ckeditor\\Field';
                }
            }

            return null;
        }

        if (class_exists($type)) {
            return $type;
        }

        $craftClass = 'craft\\fields\\' . $type;
        if (class_exists($craftClass)) {
            return $craftClass;
        }

        return null;
    }

    /**
     * Get the friendly alias map (only classes that are actually available).
     *
     * @return array<string, string> alias => FQCN
     */
    public static function getAvailableAliases(): array
    {
        $available = [];
        foreach (self::$aliasMap as $alias => $_) {
            $resolved = self::resolve($alias);
            if ($resolved !== null) {
                $available[$alias] = $resolved;
            }
        }
        return $available;
    }

    /**
     * Get all field types registered in the system (including plugins).
     *
     * @return string[]
     */
    public static function getAllRegisteredTypes(): array
    {
        return Craft::$app->fields->getAllFieldTypes();
    }

    /**
     * Build default config for a field type based on provided key-value settings.
     * Applies sensible defaults for common field types.
     */
    public static function buildConfig(string $className, array $overrides = []): array
    {
        $defaults = match ($className) {
            PlainText::class => [
                'multiline' => false,
                'charLimit' => null,
                'initialRows' => 4,
            ],
            Number::class => [
                'min' => null,
                'max' => null,
                'decimals' => 0,
            ],
            Date::class => [
                'showDate' => true,
                'showTime' => false,
            ],
            Lightswitch::class => [
                'default' => false,
            ],
            Assets::class => [
                'maxRelations' => null,
                'allowedKinds' => null,
                'allowUploads' => true,
            ],
            Entries::class => [
                'maxRelations' => null,
                'sources' => '*',
            ],
            Categories::class => [
                'maxRelations' => null,
                'sources' => '*',
                'allowSelfRelations' => false,
            ],
            Users::class => [
                'maxRelations' => null,
            ],
            Tags::class => [
                'maxRelations' => null,
            ],
            default => [],
        };

        return array_merge($defaults, $overrides);
    }

    /**
     * Get a short, human-readable label for a field class.
     */
    public static function shortLabel(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
