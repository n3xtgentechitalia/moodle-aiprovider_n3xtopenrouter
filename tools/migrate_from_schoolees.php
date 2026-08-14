<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Move existing provider instances onto this component after the rename.
 *
 *   php tools/migrate_from_schoolees.php --moodle=/path/to/moodle [--dry-run] [--yes]
 *
 * Moodle has no built-in way to rename a component, and uninstalling the old plugin
 * DELETES its provider instances, API key included - see
 * lib/classes/plugininfo/aiprovider.php::uninstall_cleanup(). So the order is:
 *
 *   1. install this plugin (both can coexist)
 *   2. run this script, which repoints the ai_providers rows
 *   3. verify with tools/verify_installed.php
 *   4. only then uninstall the old plugin: it finds no rows of its own
 *
 * Every row is written to a JSON backup before anything changes.
 *
 * Run as the user that owns moodledata. Self-contained, so it can be copied
 * somewhere that user can read.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const NEW_COMPONENT = 'aiprovider_n3xtopenrouter';
const DEFAULT_OLD_COMPONENT = 'aiprovider_schooleesopenrouter';

$moodle = '';
$old = DEFAULT_OLD_COMPONENT;
$dryrun = false;
$assumeyes = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--moodle=')) {
        $moodle = rtrim(substr($arg, strlen('--moodle=')), '/');
    } else if (str_starts_with($arg, '--from=')) {
        $old = substr($arg, strlen('--from='));
    } else if ($arg === '--dry-run') {
        $dryrun = true;
    } else if ($arg === '--yes') {
        $assumeyes = true;
    }
}
if ($moodle === '' || !is_file($moodle . '/config.php')) {
    fwrite(STDERR, "Pass --moodle=/path/to/moodle (the directory holding config.php)\n");
    exit(2);
}

define('CLI_SCRIPT', true);
require($moodle . '/config.php');

global $DB;

$oldclass = $old . '\\provider';
$newclass = NEW_COMPONENT . '\\provider';

printf("migrating provider instances\n");
printf("  from: %s\n", $oldclass);
printf("  to:   %s\n\n", $newclass);

if (!get_config(NEW_COMPONENT, 'version')) {
    fwrite(STDERR, NEW_COMPONENT . " is not installed yet. Install it first, then migrate.\n");
    exit(1);
}

$rows = $DB->get_records('ai_providers', ['provider' => $oldclass]);
if (!$rows) {
    printf("Nothing to migrate: no ai_providers rows reference %s.\n", $oldclass);
    $already = $DB->count_records('ai_providers', ['provider' => $newclass]);
    printf("(%d row%s already on the new component.)\n", $already, $already === 1 ? '' : 's');
    exit(0);
}

foreach ($rows as $row) {
    $config = json_decode($row->config ?? '{}', true) ?: [];
    $actionconfig = json_decode($row->actionconfig ?? '{}', true) ?: [];
    printf("  instance %d \"%s\" (%s)\n", $row->id, $row->name, $row->enabled ? 'enabled' : 'disabled');
    printf("     API key:  %s\n", empty($config['apikey'])
        ? 'none' : 'present, ' . strlen((string) $config['apikey']) . ' chars');
    foreach ($actionconfig as $actionclass => $block) {
        $short = substr($actionclass, strrpos($actionclass, '\\') + 1);
        $model = $block['settings']['model'] ?? '(unset)';
        printf("     %-16s %-9s model: %s\n", $short, !empty($block['enabled']) ? 'enabled' : 'disabled', $model);
    }
}

// Legacy site-level settings, if this site ever had any.
$legacy = $DB->get_records('config_plugins', ['plugin' => $old]);
$legacy = array_filter($legacy, fn($r) => $r->name !== 'version');
if ($legacy) {
    printf("\n  plus %d site-level setting(s) to copy: %s\n", count($legacy),
        implode(', ', array_map(fn($r) => $r->name, $legacy)));
}

if ($dryrun) {
    printf("\n[dry run] nothing was changed.\n");
    exit(0);
}

if (!$assumeyes) {
    printf("\nRepoint %d instance(s) at %s? [y/N] ", count($rows), NEW_COMPONENT);
    if (!preg_match('/^y/i', trim((string) fgets(STDIN)))) {
        print("Aborted.\n");
        exit(1);
    }
}

$backup = sprintf('%s/ai_providers-migration-%s.json', sys_get_temp_dir(), date('Ymd-His'));
file_put_contents($backup, json_encode([
    'from' => $oldclass,
    'to' => $newclass,
    'rows' => array_values(array_map(fn($r) => (array) $r, $rows)),
    'config_plugins' => array_values(array_map(fn($r) => (array) $r, $legacy)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
printf("\n  backup written: %s\n", $backup);

$transaction = $DB->start_delegated_transaction();
try {
    foreach ($rows as $row) {
        $DB->set_field('ai_providers', 'provider', $newclass, ['id' => $row->id]);
        printf("  instance %d repointed\n", $row->id);
    }
    foreach ($legacy as $record) {
        if (get_config(NEW_COMPONENT, $record->name) === false) {
            set_config($record->name, $record->value, NEW_COMPONENT);
            printf("  setting %s copied\n", $record->name);
        }
    }
    $transaction->allow_commit();
} catch (\Throwable $e) {
    $transaction->rollback($e);
}

purge_all_caches();

$moved = $DB->count_records('ai_providers', ['provider' => $newclass]);
$left = $DB->count_records('ai_providers', ['provider' => $oldclass]);
printf("\n  now on %s: %d\n", NEW_COMPONENT, $moved);
printf("  still on %s: %d\n", $old, $left);

print("\nMigrated. Next:\n");
print("  1. Verify:   php tools/verify_installed.php --moodle=" . $moodle . "\n");
print("  2. Then, and only then, uninstall the old plugin:\n");
printf("       php admin/cli/uninstall_plugins.php --plugins=%s --run\n", $old);
print("     It will find no rows of its own, so nothing is lost.\n");

exit($left === 0 ? 0 : 1);
