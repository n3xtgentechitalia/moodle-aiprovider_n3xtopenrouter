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
 * Make real requests through an installed copy of this plugin.
 *
 *   php tools/smoke_test.php --moodle=/path/to/moodle [--images] [--yes]
 *
 * This SPENDS MONEY on the OpenRouter account whose key is configured, and is the
 * only check that proves the whole path works: Moodle's AI subsystem, this
 * plugin, the network, the key, and the model.
 *
 * Run it as the user that owns moodledata, so that generated files are not left
 * owned by root. The script is deliberately self-contained, so it can be copied
 * somewhere that user can read:
 *
 *   install -m 644 tools/smoke_test.php /tmp/smoke_test.php
 *   runuser -u www-data -- php /tmp/smoke_test.php --moodle=/path/to/moodle
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const COMPONENT = 'aiprovider_n3xtopenrouter';

$moodle = '';
$withimages = false;
$assumeyes = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--moodle=')) {
        $moodle = rtrim(substr($arg, strlen('--moodle=')), '/');
    } else if ($arg === '--images') {
        $withimages = true;
    } else if ($arg === '--yes') {
        $assumeyes = true;
    }
}

if ($moodle === '') {
    // Fall back to the Moodle this file sits inside, when run from an install.
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

global $DB, $CFG;

$failures = 0;

/**
 * Print a labelled result.
 *
 * @param bool $ok
 * @param string $label
 * @param string $detail
 */
function report(bool $ok, string $label, string $detail = ''): void {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  %s  %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $detail === '' ? '' : ": {$detail}");
}

print("=== Installed plugin ===\n");
$installed = get_config(COMPONENT, 'version');
report((bool) $installed, 'component registered', $installed ? "version {$installed}" : 'not installed');
if (!$installed) {
    fwrite(STDERR, "\nThe plugin is not installed in this Moodle. Deploy it first.\n");
    exit(1);
}

print("\n=== Provider instances ===\n");
$manager = \core\di::get(\core_ai\manager::class);
$instances = [];
foreach ($DB->get_records('ai_providers') as $record) {
    if (!str_starts_with($record->provider, COMPONENT)) {
        continue;
    }
    $instances[] = $record;
    $config = json_decode($record->config ?? '{}', true) ?: [];
    $haskey = !empty($config['apikey']);
    printf(
        "  instance %d \"%s\": %s, API key %s\n",
        $record->id,
        $record->name,
        $record->enabled ? 'enabled' : 'DISABLED',
        $haskey ? 'configured (' . strlen((string) $config['apikey']) . ' chars)' : 'MISSING'
    );

    $actionconfig = json_decode($record->actionconfig ?? '{}', true) ?: [];
    foreach (\aiprovider_n3xtopenrouter\provider::get_action_list() as $actionclass) {
        $short = substr($actionclass, strrpos($actionclass, '\\') + 1);
        $known = array_key_exists($actionclass, $actionconfig);
        $settings = $actionconfig[$actionclass]['settings'] ?? [];
        $model = $settings['model'] ?? null;
        printf(
            "     %-16s %-9s model: %s\n",
            $short,
            !$known ? 'unknown' : (!empty($actionconfig[$actionclass]['enabled']) ? 'enabled' : 'disabled'),
            $model !== null && $model !== '' ? $model : '(unset, plugin default applies)'
        );
    }
}
report(count($instances) > 0, 'at least one provider instance exists');
if (!$instances) {
    exit(1);
}

$live = array_filter($instances, fn($i) => $i->enabled && !empty(json_decode($i->config ?? '{}', true)['apikey']));
report(count($live) > 0, 'an enabled instance has an API key');
if (!$live) {
    fwrite(STDERR, "\nNothing to test against.\n");
    exit(1);
}

if (!$assumeyes) {
    $what = $withimages ? 'a text request and one image generation' : 'one text request';
    printf("\nThis sends %s to OpenRouter and your account WILL be charged.\n", $what);
    print("Continue? [y/N] ");
    $reply = trim((string) fgets(STDIN));
    if (!preg_match('/^y/i', $reply)) {
        print("Aborted.\n");
        exit(1);
    }
}

$admin = get_admin();
$context = \context_system::instance();

print("\n=== Generate text (real request) ===\n");
$action = new \core_ai\aiactions\generate_text(
    contextid: $context->id,
    userid: (int) $admin->id,
    prompttext: 'Reply with the single word OK and nothing else.',
);
$started = microtime(true);
$result = $manager->process_action($action);
$elapsed = microtime(true) - $started;

if (!$result->get_success()) {
    report(false, 'request', sprintf('code %s: %s', $result->get_errorcode(), $result->get_errormessage()));
} else {
    $data = $result->get_response_data();
    report(true, 'request', sprintf('%.2fs', $elapsed));
    printf("        model that answered: %s\n", $data['model'] ?? '(none reported)');
    printf("        tokens prompt/completion: %s/%s\n", $data['prompttokens'] ?? '?', $data['completiontokens'] ?? '?');
    printf("        finish reason: %s\n", $data['finishreason'] ?? '?');
    printf("        content: %s\n", trim((string) ($data['generatedcontent'] ?? '')));
    report(!empty($data['model']), 'the resolved model is reported');
    report(trim((string) ($data['generatedcontent'] ?? '')) !== '', 'content is not empty');
}

if ($withimages) {
    print("\n=== Generate image (real request) ===\n");
    $imageclass = \core_ai\aiactions\generate_image::class;
    $enabled = $manager->is_action_enabled(COMPONENT, $imageclass, (int) reset($live)->id);
    if (!$enabled) {
        report(false, 'the image action is enabled', 'enable it under AI providers -> your instance -> Actions');
    } else {
        $action = new \core_ai\aiactions\generate_image(
            contextid: $context->id,
            userid: (int) $admin->id,
            prompttext: 'A simple flat illustration of a blue book on a white background',
            quality: 'standard',
            aspectratio: 'square',
            numimages: 1,
            style: 'natural',
        );
        $started = microtime(true);
        $result = $manager->process_action($action);
        $elapsed = microtime(true) - $started;

        if (!$result->get_success()) {
            report(false, 'request', sprintf('code %s: %s', $result->get_errorcode(), $result->get_errormessage()));
        } else {
            $data = $result->get_response_data();
            report(true, 'request', sprintf('%.2fs', $elapsed));
            $file = $data['draftfile'] ?? null;
            if ($file instanceof \stored_file) {
                printf(
                    "        draft file: %s (%s, %d bytes)\n",
                    $file->get_filename(),
                    $file->get_mimetype(),
                    $file->get_filesize()
                );
                report($file->get_filesize() > 0, 'the image file has content');
            } else {
                report(false, 'a draft file was produced');
            }
            printf("        model: %s\n", $data['model'] ?? '(none reported)');
            if (!empty($data['revisedprompt'])) {
                printf("        revised prompt: %s\n", $data['revisedprompt']);
            }
        }
    }
}

printf("\n%s\n", $failures === 0 ? 'SMOKE TEST PASSED' : "SMOKE TEST FAILED ({$failures} failures)");
exit($failures === 0 ? 0 : 1);
