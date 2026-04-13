<?php

namespace sustdev\fieldmanager;

use Craft;

/**
 * Field Manager plugin for Craft CMS 5.
 *
 * Provides CLI commands for managing fields and field layouts,
 * designed for AI agents and developers.
 *
 * All commands use the Craft PHP API — no manual YAML editing or
 * UUID generation needed. Craft handles project config automatically.
 *
 * @method static Plugin getInstance()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        if (Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'sustdev\\fieldmanager\\console\\controllers';
        }
    }
}
