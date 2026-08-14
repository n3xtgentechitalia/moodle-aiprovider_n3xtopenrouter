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
 * Check an installed copy of this plugin, without spending anything.
 *
 *   php tools/verify_installed.php --moodle=/path/to/moodle
 *
 * Run after deploying. It exercises the installed code through Moodle's own
 * autoloader, so it checks what the site will actually run: the registered
 * version, the cache definition, both live catalogues, the provider instances and
 * whether their credentials survived, and that every settings form renders with
 * no unresolved language strings.
 *
 * Run it as the user that owns moodledata: it warms the model cache, and those
 * files must not end up owned by root.
 *
 * Self-contained on purpose, so it can be copied somewhere that user can read.
 * No request is sent to OpenRouter beyond the public, unauthenticated model
 * catalogues, so nothing is billed. Use tools/smoke_test.php for a real request.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const COMPONENT = 'aiprovider_n3xtopenrouter';
const EXPECTED_ACTIONS = ['generate_text', 'summarise_text', 'generate_image'];

$moodle = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--moodle=')) {
        $moodle = rtrim(substr($arg, strlen('--moodle=')), '/');
    }
}
if ($moodle === '') {
    $guess = dirname(__DIR__, 4);
    if (is_file($guess . '/config.php')) {
        $moodle = $guess;
    }
}
if ($moodle === '' || !is_file($moodle . '/config.php')) {
    fwrite(STDERR, "Pass --moodle=/path/to/moodle (the directory holding config.php)\n");
    exit(2);
}

define('CLI_SCRIPT', true);
require($moodle . '/config.php');

global $DB, $CFG, $PAGE;

$failures = 0;

/**
 * Print a labelled result.
 *
 * @param bool $ok
 * @param string $label
 * @param string $detail
 */
function ck(bool $ok, string $label, string $detail = ''): void {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  %s  %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $detail === '' ? '' : ": {$detail}");
}

print("=== Installed code ===\n");
$version = get_config(COMPONENT, 'version');
ck((bool) $version, 'component registered', (string) $version);
foreach (['provider', 'model_list', 'process_generate_text', 'process_generate_image'] as $class) {
    ck(class_exists('\\' . COMPONENT . '\\' . $class), "class {$class} autoloads");
}
// Removed in 1.1.0: it was a no-op wrapping unreachable legacy configuration.
ck(!file_exists($CFG->dirroot . '/ai/provider/n3xtopenrouter/settings.php'), 'legacy settings.php is gone');
$actual = array_map(
    fn($a) => substr($a, strrpos($a, '\\') + 1),
    \aiprovider_n3xtopenrouter\provider::get_action_list()
);
ck($actual === EXPECTED_ACTIONS, 'supported actions', implode(', ', $actual));

print("\n=== Cache definition ===\n");
try {
    $cache = \core_cache\cache::make(COMPONENT, 'models');
    ck(true, 'the models cache is available', get_class($cache));
} catch (\Throwable $e) {
    ck(false, 'the models cache is available', $e->getMessage());
}

print("\n=== Model catalogues ===\n");
$text = \aiprovider_n3xtopenrouter\model_list::get_text_models();
$images = \aiprovider_n3xtopenrouter\model_list::get_image_models();
ck(count($text) > 20, 'text models', (string) count($text));
ck(count($images) > 5, 'image models', (string) count($images));
ck(\aiprovider_n3xtopenrouter\model_list::is_live(), 'text catalogue came from OpenRouter and cached');
ck(\aiprovider_n3xtopenrouter\model_list::image_list_is_live(), 'image catalogue came from OpenRouter and cached');

print("\n=== Provider instances ===\n");
$instances = array_filter(
    $DB->get_records('ai_providers'),
    fn($r) => str_starts_with($r->provider, COMPONENT)
);
if (empty($instances)) {
    // Normal for a fresh install: an admin creates the instance afterwards.
    print("  info  no provider instance yet: create one under AI > AI providers\n");
}
foreach ($instances as $record) {
    $config = json_decode($record->config ?? '{}', true) ?: [];
    $actionconfig = json_decode($record->actionconfig ?? '{}', true) ?: [];
    printf("  instance %d \"%s\": %s\n", $record->id, $record->name, $record->enabled ? 'enabled' : 'disabled');
    ck(!empty($config['apikey']), "  instance {$record->id} has an API key", strlen((string) ($config['apikey'] ?? '')) . ' chars');
    foreach (\aiprovider_n3xtopenrouter\provider::get_action_list() as $actionclass) {
        $short = substr($actionclass, strrpos($actionclass, '\\') + 1);
        if (!array_key_exists($actionclass, $actionconfig)) {
            // An action added by a later release: the stored config predates it,
            // so it stays off until an admin enables it. Not a failure.
            printf("       %-16s not in stored config yet, enable it under Actions\n", $short);
            continue;
        }
        $settings = $actionconfig[$actionclass]['settings'] ?? [];
        printf(
            "       %-16s %-12s model: %s\n",
            $short,
            !empty($actionconfig[$actionclass]['enabled']) ? 'enabled' : 'disabled',
            $settings['model'] ?? '(unset, plugin default applies)'
        );
    }
}

print("\n=== Settings forms render, with all strings resolved ===\n");
$PAGE->set_url('/');
$CFG->debug = 0;
$CFG->debugdisplay = 0;
require_once($CFG->libdir . '/formslib.php');
foreach (EXPECTED_ACTIONS as $short) {
    $actionclass = 'core_ai\\aiactions\\' . $short;
    try {
        $form = \aiprovider_n3xtopenrouter\provider::get_action_settings($actionclass, ['providerid' => 1]);
        ob_start();
        $form->display();
        $html = ob_get_clean();

        preg_match_all('/\[\[[a-z_:0-9]+\]\]/i', $html, $matches);
        $unresolved = array_values(array_unique($matches[0]));
        ck(
            empty($unresolved) && strlen($html) > 1000,
            "{$short} form renders",
            $unresolved ? 'unresolved strings: ' . implode(' ', $unresolved) : strlen($html) . ' bytes'
        );
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ck(false, "{$short} form renders", get_class($e) . ': ' . $e->getMessage());
    }
}

printf("\nmaintenance mode: %s\n", empty($CFG->maintenance_enabled) ? 'off' : 'ON');
printf("%s\n", $failures === 0 ? 'INSTALL CHECK PASSED' : "INSTALL CHECK FAILED ({$failures} failures)");
exit($failures === 0 ? 0 : 1);
