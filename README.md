# Field Manager CLI — Craft CMS Plugin

CLI plugin for Craft CMS 5 that manages fields and field layouts programmatically. Built for AI agents and developers who need to modify content schemas without the control panel.

**No manual YAML editing. No UUID generation. Craft handles it all.**

## Installation

### 1. Add the plugin to your project

Create a `plugins/` directory in your project root (if it doesn't exist) and place this plugin in `plugins/craft-field-manager/`.

### 2. Add the path repository to `composer.json`

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "plugins/craft-field-manager"
    }
  ]
}
```

### 3. Require and install

```bash
ddev composer require sustdev/craft-field-manager:@dev
ddev craft plugin/install fm
```

### 4. Set up AI rules

See [AI Agent Setup](#ai-agent-setup) below — this is the critical step that makes AI agents use the CLI instead of editing YAML directly.

---

## Quick start

```bash
# 1. See what's there
ddev craft fm/schema/overview --format=json

# 2. Create a field and add to layout
ddev craft fm/fields/create-and-add \
  --name="Subtitle" --type=plaintext --entryType=insight --tab=Content --after=title

# 3. Verify
ddev craft fm/layout/show --entryType=insight
```

---

## AI Agent Setup

This plugin only works well if AI agents **know it exists** and **know to use it** instead of manually editing YAML files and generating UUIDs. Without rules, an AI will default to writing YAML in `config/project/` — which is fragile and error-prone.

You need to set up rules for every AI tool you use. Below are copy-paste ready configurations for each.

### Cursor (`.cursor/rules/`)

Create the file `.cursor/rules/craft-field-manager.mdc` in your project root:

```markdown
---
description: Craft CMS velden beheren via de fm CLI plugin — nooit handmatig YAML of UUIDs schrijven
globs: "config/project/**/*.yaml,modules/**/*.php,plugins/**/*.php"
alwaysApply: true
---

# Craft CMS velden beheren — altijd via fm CLI

## Gouden regel

Gebruik **altijd** de `fm` CLI plugin om velden aan te maken en aan layouts toe te voegen.
**Nooit** handmatig YAML bewerken in `config/project/`, **nooit** UUIDs genereren.
Craft genereert UUIDs automatisch wanneer je de PHP API gebruikt via deze CLI.

## Workflow

### 1. Inspecteer eerst het schema

​```bash
ddev craft fm/schema/overview --format=json
​```

### 2. Maak het veld aan en voeg toe (1 commando)

​```bash
ddev craft fm/fields/create-and-add \
  --name="Subtitle" \
  --type=plaintext \
  --entryType=insight \
  --tab=Content \
  --after=title
​```

### 3. Verifieer

​```bash
ddev craft fm/layout/show --entryType=insight
​```

## Alle commando's

| Commando | Wat het doet |
|---|---|
| `fm/fields/list` | Alle velden |
| `fm/fields/types` | Beschikbare types + aliassen |
| `fm/fields/show --handle=X` | Veld details |
| `fm/fields/create --name="X" --type=Y` | Veld aanmaken |
| `fm/fields/create-and-add --name="X" --type=Y --entryType=Z` | Aanmaken + in layout |
| `fm/fields/update --handle=X --new-handle=Y` | Handle/naam/type aanpassen |
| `fm/fields/delete --handle=X [--force] [--dry-run]` | Veld verwijderen (refuseert bij gebruik tenzij `--force`) |
| `fm/layout/show --entryType=X` | Layout tonen |
| `fm/layout/add-field --entryType=X --field=Y --tab=Z --after=W` | Veld toevoegen (`--after`, `--before` of `--position`) |
| `fm/layout/reorder --entryType=X --field=Y --after=Z` | Veld verplaatsen (tab behouden tenzij `--tab` opgegeven, `--before` ook mogelijk) |
| `fm/layout/remove-field --entryType=X --field=Y` | Veld verwijderen uit layout |
| `fm/layout/list-entry-types [--section=X]` | Entry types |
| `fm/layout/list-sections` | Secties |
| `fm/entry-types/delete --handle=X [--force] [--dry-run]` | Entry type verwijderen (refuseert bij gebruik tenzij `--force`) |
| `fm/schema/overview [--format=json]` | Volledig schema |
| `fm/schema/section --section=X [--format=json]` | Schema per sectie |

## Veldtype aliassen

| Korte naam | Type |
|---|---|
| `plaintext`, `text` | Plain text |
| `richtext`, `redactor`, `wysiwyg` | Redactor rich text |
| `number` | Getal |
| `date` | Datum |
| `lightswitch`, `boolean`, `toggle` | Aan/uit |
| `dropdown`, `select` | Dropdown |
| `entries` | Entries relatie |
| `assets` | Assets relatie |
| `email`, `url`, `color`, `money` | Diversen |

## Positionering

- `--tab=Content` — in welk tabblad
- `--after=fieldHandle` — na een specifiek veld (aanbevolen)
- `--before=fieldHandle` — voor een specifiek veld
- `--position=N` — op index N (0-based)
- `--position=after:fieldHandle` / `--position=before:fieldHandle` — handle-vorm, gelijkwaardig aan `--after` / `--before`
- `--required` — verplicht veld
- Zonder positie → achteraan in de tab

Combineer `--after`, `--before` en `--position` niet in één commando.

## Settings via JSON

​```bash
--settings='{"decimals":2,"min":0}'
--settings='{"maxRelations":1,"allowedKinds":["image"]}'
--options='[{"label":"Draft","value":"draft"},{"label":"Active","value":"active"}]'
​```

## Regels

1. **Inspecteer altijd eerst** het schema voor je wijzigingen maakt
2. **Gebruik handles** — nooit UUIDs, nooit numerieke IDs
3. **Gebruik `--after=handle`** voor positionering
4. **Gebruik `create-and-add`** als je een nieuw veld + layout wilt
5. **Bewerk nooit YAML** in `config/project/`
6. **Genereer nooit UUIDs**
7. **Secties en entry types** worden handmatig aangemaakt, niet via CLI
8. **Verifieer** met `fm/layout/show` na elke wijziging
```

> **Waarom `alwaysApply: true`?** Dit zorgt ervoor dat de regel altijd actief is, ongeacht welk bestand de agent bekijkt. Zonder dit vergeet de AI de plugin zodra hij buiten `config/project/` werkt.

### Claude Code / Claude Desktop (`CLAUDE.md`)

Maak een `CLAUDE.md` bestand aan in je project root. Claude Code en Claude Desktop lezen dit automatisch als project-context.

```markdown
# Craft CMS Field Manager

Dit project heeft de `fm` (Field Manager) plugin geinstalleerd.
Gebruik deze CLI om velden aan te maken en aan entry type layouts toe te voegen.

**Nooit** handmatig YAML bewerken in `config/project/`. **Nooit** UUIDs genereren.
Craft regelt dat automatisch via de PHP API.

## Stap 1: Schema inspecteren

​```bash
ddev craft fm/schema/overview --format=json
​```

## Stap 2: Veld aanmaken + toevoegen (1 commando)

​```bash
ddev craft fm/fields/create-and-add \
  --name="Subtitle" \
  --type=plaintext \
  --entryType=insight \
  --tab=Content \
  --after=title
​```

## Stap 3: Verifiëren

​```bash
ddev craft fm/layout/show --entryType=insight
​```

## Regels

1. **Inspecteer altijd eerst** — draai `fm/schema/overview --format=json` voor je iets wijzigt
2. **Gebruik handles** — nooit UUIDs, nooit numerieke IDs
3. **Gebruik `--after`** — niet `--position`, tenzij je het veld bovenaan wilt
4. **Eén commando** — gebruik `create-and-add` in plaats van los `create` + `add-field`
5. **Bewerk nooit YAML** — geen bestanden in `config/project/` aanraken
6. **Genereer nooit UUIDs** — Craft doet dat automatisch
7. **Secties en entry types** — worden handmatig aangemaakt, niet via deze CLI
8. **Verifieer achteraf** — draai `fm/layout/show` na een wijziging

## Alle commando's

| Commando | Wat het doet |
|---|---|
| `fm/fields/list` | Alle velden tonen |
| `fm/fields/types` | Beschikbare veldtypes + aliassen |
| `fm/fields/show --handle=X` | Details van een veld |
| `fm/fields/create --name="X" --type=Y` | Veld aanmaken (zonder layout) |
| `fm/fields/create-and-add --name="X" --type=Y --entryType=Z` | Veld aanmaken + in layout |
| `fm/fields/update --handle=X --new-handle=Y` | Handle, naam en/of type aanpassen |
| `fm/fields/delete --handle=X [--force] [--dry-run]` | Veld verwijderen (refuseert bij gebruik tenzij `--force`) |
| `fm/layout/show --entryType=X` | Layout tonen |
| `fm/layout/add-field --entryType=X --field=Y` | Veld toevoegen aan layout |
| `fm/layout/reorder --entryType=X --field=Y --after=Z` | Veld verplaatsen |
| `fm/layout/remove-field --entryType=X --field=Y` | Veld verwijderen uit layout |
| `fm/layout/list-entry-types [--section=X]` | Entry types |
| `fm/layout/list-sections` | Secties |
| `fm/entry-types/delete --handle=X [--force] [--dry-run]` | Entry type verwijderen (refuseert bij gebruik tenzij `--force`) |
| `fm/schema/overview [--format=json]` | Volledig schema |
| `fm/schema/section --section=X [--format=json]` | Schema per sectie |

## Veldtype aliassen

`plaintext`, `richtext`/`redactor`/`wysiwyg`, `number`, `date`, `lightswitch`/`boolean`,
`dropdown`/`select`, `entries`, `assets`, `categories`, `tags`, `users`,
`email`, `url`, `color`, `money`, `table`, `country`, `checkboxes`, `radio`

Alle types: `ddev craft fm/fields/types`

## Omgeving

- Alle commando's via `ddev craft ...` (nooit direct `php craft`)
- Craft CMS 5.x
- Plugin handle: `fm`
```

### Windsurf (`.windsurfrules`)

Voeg het volgende toe aan `.windsurfrules` in je project root:

```
When working with Craft CMS fields or content schema:
- Always use the fm CLI plugin: ddev craft fm/fields/create-and-add
- Never edit YAML files in config/project/ directly
- Never generate UUIDs manually
- Inspect schema first: ddev craft fm/schema/overview --format=json
- Verify changes: ddev craft fm/layout/show --entryType=<handle>
- Use --after=fieldHandle for positioning (not numeric --position)
- Field type aliases: plaintext, richtext, number, date, lightswitch, dropdown, entries, assets
```

### GitHub Copilot (`.github/copilot-instructions.md`)

Voeg het volgende toe aan `.github/copilot-instructions.md`:

```markdown
## Craft CMS Field Management

This project uses the `fm` CLI plugin for field management. Never edit YAML in `config/project/` or generate UUIDs manually.

Commands:
- Inspect: `ddev craft fm/schema/overview --format=json`
- Create + add: `ddev craft fm/fields/create-and-add --name="X" --type=Y --entryType=Z --tab=Content --after=fieldHandle`
- Verify: `ddev craft fm/layout/show --entryType=X`
- Types: `ddev craft fm/fields/types`

Always inspect the schema first, use handles (not UUIDs), and verify after changes.
```

### Aider (`.aider.conf.yml` of conventie)

Voeg context toe via een repo-map of conventions bestand:

```
# In je .aider.conf.yml of als read-only file:
read: CLAUDE.md
```

Aider leest `CLAUDE.md` als je het toevoegt als context-bestand bij het starten van een sessie.

### Overzicht: wat moet waar?

| AI Tool | Bestand | Locatie |
|---|---|---|
| **Cursor** | `.cursor/rules/craft-field-manager.mdc` | Project root |
| **Claude Code** | `CLAUDE.md` | Project root |
| **Claude Desktop** | `CLAUDE.md` | Project root |
| **Windsurf** | `.windsurfrules` | Project root |
| **GitHub Copilot** | `.github/copilot-instructions.md` | Project root |
| **Aider** | `CLAUDE.md` (als context) | Project root |

> **Tip:** Zet op z'n minst de `CLAUDE.md` in elk project. De meeste AI tools kunnen dit bestand lezen of er naar verwijzen, en het bevat de complete instructieset.

---

## Commands

### Fields (`fm/fields/...`)

```bash
# List all fields
ddev craft fm/fields/list

# Available field types and aliases
ddev craft fm/fields/types

# Field details
ddev craft fm/fields/show --handle=myField

# Create a field
ddev craft fm/fields/create --name="Subtitle" --type=plaintext

# Create AND add to layout (recommended)
ddev craft fm/fields/create-and-add \
  --name="Subtitle" --type=plaintext --entryType=insight --tab=Content --after=title

# Update name, handle and/or type
ddev craft fm/fields/update --handle=oldHandle --new-handle=newHandle
ddev craft fm/fields/update --handle=myField --new-name="New Name" --new-type=richtext

# Delete a field (refuses if still used in any layout unless --force)
ddev craft fm/fields/delete --handle=subtitle
ddev craft fm/fields/delete --handle=subtitle --dry-run
ddev craft fm/fields/delete --handle=subtitle --force
```

### Layouts (`fm/layout/...`)

```bash
# Show entry type layout
ddev craft fm/layout/show --entryType=insight

# Add field to layout
ddev craft fm/layout/add-field --entryType=insight --field=subtitle --tab=Content --after=title
ddev craft fm/layout/add-field --entryType=insight --field=subtitle --before=footer
ddev craft fm/layout/add-field --entryType=insight --field=subtitle --position=after:title

# Move field to a different position (stays in same tab unless --tab specified)
ddev craft fm/layout/reorder --entryType=insight --field=subtitle --after=title
ddev craft fm/layout/reorder --entryType=insight --field=subtitle --before=footer
ddev craft fm/layout/reorder --entryType=insight --field=subtitle --position=0
ddev craft fm/layout/reorder --entryType=insight --field=subtitle --position=after:title

# Remove field from layout
ddev craft fm/layout/remove-field --entryType=insight --field=subtitle

# List entry types (all or by section)
ddev craft fm/layout/list-entry-types
ddev craft fm/layout/list-entry-types --section=insights

# List sections
ddev craft fm/layout/list-sections
```

### Entry types (`fm/entry-types/...`)

```bash
# Delete an entry type (refuses if still used in any section or Matrix field unless --force)
ddev craft fm/entry-types/delete --handle=oldBlock
ddev craft fm/entry-types/delete --handle=oldBlock --dry-run
ddev craft fm/entry-types/delete --handle=oldBlock --force
```

Entry type creation is intentionally out of scope — create entry types via the control panel or project config.

### Schema inspection (`fm/schema/...`)

```bash
# Full schema
ddev craft fm/schema/overview

# Full schema as JSON (for AI parsing)
ddev craft fm/schema/overview --format=json

# Single section
ddev craft fm/schema/section --section=insights --format=json
```

### Neo block types (`fm/neo/...`)

For fields of type `neo` (`benf/craft-neo` plugin required).

```bash
# Show a Neo field's block types and their fields
ddev craft fm/neo/show --field=myNeoField

# Add a block type to a Neo field
ddev craft fm/neo/add-block --field=myNeoField --name="Hero Banner"
ddev craft fm/neo/add-block --field=myNeoField --name="Hero Banner" --handle=heroBanner --top-level=1

# Add a Craft field to a Neo block type's layout
ddev craft fm/neo/add-field --field=myNeoField --block=heroBanner --field-handle=title --required
ddev craft fm/neo/add-field --field=myNeoField --block=heroBanner --field-handle=subtitle --tab=Content --after=title
```

**Typical workflow:**

```bash
# 1. Create the Neo field
ddev craft fm/fields/create-and-add --name="Page Components" --type=neo --entryType=page

# 2. Add block types
ddev craft fm/neo/add-block --field=pageComponents --name="Hero Section" --handle=heroSection

# 3. Add fields to block types
ddev craft fm/neo/add-field --field=pageComponents --block=heroSection --field-handle=title --required
ddev craft fm/neo/add-field --field=pageComponents --block=heroSection --field-handle=body --after=title

# 4. Verify
ddev craft fm/neo/show --field=pageComponents
```

Note: `--field-handle` targets the Craft field to add; `--field` is always the Neo field handle.

## Field type aliases

| Alias(es) | Craft class |
|---|---|
| `plaintext`, `text` | `craft\fields\PlainText` |
| `richtext`, `redactor`, `wysiwyg` | `craft\redactor\Field` (or CKEditor if installed) |
| `ckeditor` | `craft\ckeditor\Field` |
| `neo` | `benf\neo\Field` (requires spicyweb/craft-neo) |
| `number` | `craft\fields\Number` |
| `date`, `datetime` | `craft\fields\Date` |
| `time` | `craft\fields\Time` |
| `lightswitch`, `boolean`, `toggle` | `craft\fields\Lightswitch` |
| `dropdown`, `select` | `craft\fields\Dropdown` |
| `checkboxes` | `craft\fields\Checkboxes` |
| `radio`, `radiobuttons` | `craft\fields\RadioButtons` |
| `multiselect` | `craft\fields\MultiSelect` |
| `entries` | `craft\fields\Entries` |
| `assets` | `craft\fields\Assets` |
| `categories` | `craft\fields\Categories` |
| `tags` | `craft\fields\Tags` |
| `users` | `craft\fields\Users` |
| `email` | `craft\fields\Email` |
| `url` | `craft\fields\Url` |
| `color`, `colour` | `craft\fields\Color` |
| `money` | `craft\fields\Money` |
| `table` | `craft\fields\Table` |
| `country` | `craft\fields\Country` |
| `range` | `craft\fields\Range` |

You can also use a fully qualified class name for plugin-specific field types.

## Positioning

| Option | Effect |
|---|---|
| `--tab="Content"` | Place in named tab (case-insensitive) |
| `--tab="New Tab"` | Creates tab if it doesn't exist |
| `--after=fieldHandle` | Place after another field (recommended) |
| `--before=fieldHandle` | Place before another field |
| `--position=N` | Place at index N (0-based) |
| `--position=after:fieldHandle` | Same as `--after=fieldHandle` |
| `--position=before:fieldHandle` | Same as `--before=fieldHandle` |
| `--required` | Mark field as required |
| _(no position)_ | Append to end of tab |

Use only one of `--after`, `--before`, or `--position` per command. Without `--tab`, the first tab is used.

## Advanced settings

Pass extra config as JSON via `--settings`:

```bash
# Number with decimals
--settings='{"decimals":2,"min":0,"max":99999}'

# Multiline plain text
--settings='{"multiline":true,"initialRows":6,"charLimit":500}'

# Assets limited to images
--settings='{"maxRelations":1,"allowedKinds":["image"],"allowUploads":true}'

# Entries relation with limit
--settings='{"maxRelations":5,"sources":"*"}'
```

Dropdown/radio/checkbox options via `--options`:

```bash
--options='[{"label":"Draft","value":"draft"},{"label":"Active","value":"active"}]'
```

## How it works

The plugin uses Craft's PHP API exclusively:

- `Craft::$app->fields->saveField()` — creates fields (Craft generates UIDs)
- `Craft::$app->fields->deleteField()` — deletes fields (project config auto-updated)
- `Craft::$app->fields->saveLayout()` — modifies field layouts
- `Craft::$app->entries->saveEntryType()` — persists entry type changes
- `Craft::$app->entries->deleteEntryType()` — deletes entry types
- `Neo::getInstance()->blockTypes->save()` — manages Neo block types

Project config YAML in `config/project/` is updated automatically by Craft. No manual YAML editing, no UUID generation.

**Tip:** If a field exists in the DB but has no YAML in `config/project/fields/`, re-save it via `fm/fields/update --handle=X --new-name="Same Name"`. This triggers Craft to write the project config file without changing the field.

## File structure

```
plugins/craft-field-manager/
├── composer.json                               # Craft plugin metadata (handle: fm)
├── README.md                                   # This file
└── src/
    ├── Plugin.php                              # Plugin bootstrap
    ├── helpers/
    │   └── FieldTypeResolver.php               # Type aliases, defaults, resolution
    └── console/controllers/
        ├── FieldsController.php                # Field CRUD + create-and-add + delete
        ├── LayoutController.php                # Layout management
        ├── EntryTypesController.php            # Entry type deletion
        ├── NeoController.php                   # Neo block type management
        └── SchemaController.php                # Schema inspection (text + JSON)
```

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
