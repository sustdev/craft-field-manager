<?php

namespace sustdev\fieldmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Console;
use sustdev\fieldmanager\helpers\FieldTypeResolver;
use sustdev\fieldmanager\helpers\LayoutHelper;
use yii\console\ExitCode;

/**
 * Schema introspection for AI agents and developers.
 *
 * Provides structured output of the entire Craft CMS content schema:
 * sections, entry types, fields, and their relationships.
 */
class SchemaController extends Controller
{
    public ?string $section = null;
    public string $format = 'text';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        return match ($actionID) {
            'overview' => array_merge($options, ['format']),
            'section' => array_merge($options, ['section', 'format']),
            default => $options,
        };
    }

    /**
     * Full schema overview: all sections, their entry types, and field layouts.
     *
     * Usage:
     *   ddev craft fm/schema/overview
     *   ddev craft fm/schema/overview --format=json
     */
    public function actionOverview(): int
    {
        $sections = Craft::$app->entries->getAllSections();

        if ($this->format === 'json') {
            return $this->outputJson($this->buildSchemaData($sections));
        }

        $this->stdout("Craft CMS Content Schema\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 80) . "\n\n");

        foreach ($sections as $section) {
            $this->stdout("Section: {$section->name} ({$section->handle})\n", Console::FG_GREEN);
            $sectionType = LayoutHelper::sectionTypeValue($section->type);
            $this->stdout("  Type: {$sectionType}\n");

            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);

            foreach ($entryTypes as $entryType) {
                $this->stdout("  Entry Type: {$entryType->name} ({$entryType->handle})\n", Console::FG_YELLOW);

                $layout = $entryType->getFieldLayout();
                if (!$layout) {
                    $this->stdout("    (no layout)\n");
                    continue;
                }

                $tabs = $layout->getTabs();
                foreach ($tabs as $tab) {
                    $this->stdout("    Tab: \"{$tab->name}\"\n", Console::FG_CYAN);
                    foreach ($tab->getElements() as $el) {
                        if ($el instanceof CustomField) {
                            $field = $el->getField();
                            if ($field) {
                                $type = FieldTypeResolver::shortLabel(get_class($field));
                                $req = $el->required ? ' *' : '';
                                $this->stdout("      - {$field->handle}: {$field->name} ({$type}){$req}\n");
                            }
                        }
                    }
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Detailed view of a single section with all its entry types and fields.
     *
     * Usage:
     *   ddev craft fm/schema/section --section=insights
     *   ddev craft fm/schema/section --section=insights --format=json
     */
    public function actionSection(): int
    {
        if (!$this->section) {
            $this->stderr("--section is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $section = Craft::$app->entries->getSectionByHandle($this->section);
        if (!$section) {
            $this->stderr("Section '{$this->section}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->format === 'json') {
            return $this->outputJson($this->buildSchemaData([$section]));
        }

        $sectionType = LayoutHelper::sectionTypeValue($section->type);
        $this->stdout("Section: {$section->name} ({$section->handle})\n", Console::FG_GREEN);
        $this->stdout("  Type: {$sectionType}\n");
        $this->stdout("  UID:  {$section->uid}\n\n");

        $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);

        foreach ($entryTypes as $entryType) {
            $this->stdout("Entry Type: {$entryType->name} ({$entryType->handle})\n", Console::FG_YELLOW);
            $this->stdout("  UID: {$entryType->uid}\n");

            $layout = $entryType->getFieldLayout();
            if (!$layout) {
                $this->stdout("  (no layout)\n\n");
                continue;
            }

            $tabs = $layout->getTabs();
            foreach ($tabs as $tabIndex => $tab) {
                $this->stdout("\n  Tab {$tabIndex}: \"{$tab->name}\"\n", Console::FG_CYAN);
                $this->stdout("  " . str_repeat('-', 70) . "\n");

                foreach ($tab->getElements() as $elIndex => $el) {
                    if ($el instanceof CustomField) {
                        $field = $el->getField();
                        if ($field) {
                            $type = FieldTypeResolver::shortLabel(get_class($field));
                            $req = $el->required ? ' [REQUIRED]' : '';
                            $this->stdout("  [{$elIndex}] {$field->handle}: {$field->name} ({$type}){$req}\n");

                            if ($field->instructions) {
                                $this->stdout("       Instructions: {$field->instructions}\n");
                            }
                        }
                    } else {
                        $className = FieldTypeResolver::shortLabel(get_class($el));
                        $this->stdout("  [{$elIndex}] <{$className}>\n", Console::FG_GREY);
                    }
                }
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    private function buildSchemaData(array $sections): array
    {
        $data = ['sections' => []];

        foreach ($sections as $section) {
            $sectionData = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => LayoutHelper::sectionTypeValue($section->type),
                'uid' => $section->uid,
                'entryTypes' => [],
            ];

            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);

            foreach ($entryTypes as $entryType) {
                $etData = [
                    'handle' => $entryType->handle,
                    'name' => $entryType->name,
                    'uid' => $entryType->uid,
                    'tabs' => [],
                ];

                $layout = $entryType->getFieldLayout();
                if ($layout) {
                    foreach ($layout->getTabs() as $tab) {
                        $tabData = [
                            'name' => $tab->name,
                            'fields' => [],
                        ];

                        foreach ($tab->getElements() as $el) {
                            if ($el instanceof CustomField) {
                                $field = $el->getField();
                                if ($field) {
                                    $tabData['fields'][] = [
                                        'handle' => $field->handle,
                                        'name' => $field->name,
                                        'type' => get_class($field),
                                        'typeShort' => FieldTypeResolver::shortLabel(get_class($field)),
                                        'required' => $el->required,
                                        'uid' => $field->uid,
                                    ];
                                }
                            }
                        }

                        $etData['tabs'][] = $tabData;
                    }
                }

                $sectionData['entryTypes'][] = $etData;
            }

            $data['sections'][] = $sectionData;
        }

        return $data;
    }

    private function outputJson(array $data): int
    {
        $this->stdout(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        return ExitCode::OK;
    }
}
