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
 * Shared harness for the verification scripts in this directory.
 *
 * These scripts exercise the plugin against a real Moodle bootstrap without
 * PHPUnit, which is useful when the target site has no test database
 * initialised. They are read-only with respect to Moodle: they construct
 * objects and inspect what would be sent, and never write to the database or
 * the file storage.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$GLOBALS['harness'] = ['pass' => 0, 'fail' => 0, 'warnings' => []];

/**
 * The plugin directory these scripts belong to.
 *
 * @return string
 */
function plugin_root(): string {
    return dirname(__DIR__);
}

/**
 * Work out which Moodle to bootstrap.
 *
 * In order: --moodle=PATH, the MOODLE_ROOT environment variable, or the Moodle
 * this plugin is installed inside.
 *
 * @param array $argv
 * @return string Directory containing config.php.
 */
function resolve_moodle_root(array $argv): string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--moodle=')) {
            return rtrim(substr($arg, strlen('--moodle=')), '/');
        }
    }

    $env = getenv('MOODLE_ROOT');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }

    // <moodle>/ai/provider/n3xtopenrouter -> <moodle>
    $installed = dirname(plugin_root(), 3);
    if (is_file($installed . '/config.php')) {
        return $installed;
    }

    fwrite(STDERR, <<<TXT
        Cannot find Moodle. Pass one of:
          --moodle=/path/to/moodle          (the directory holding config.php)
          MOODLE_ROOT=/path/to/moodle       (environment variable)
        or run this script from a plugin installed inside a Moodle tree.

        TXT);
    exit(2);
}

/**
 * Bootstrap Moodle in CLI mode.
 *
 * @param array $argv
 * @return string The Moodle root that was booted.
 */
function boot_moodle(array $argv): string {
    $moodleroot = resolve_moodle_root($argv);
    $config = $moodleroot . '/config.php';

    if (!is_file($config)) {
        fwrite(STDERR, "No config.php at {$config}\n");
        exit(2);
    }

    define('CLI_SCRIPT', true);
    require($config);

    return $moodleroot;
}

/**
 * Load the plugin classes from this checkout rather than from the installed copy.
 *
 * Requiring them before anything autoloads them means the code under test is the
 * code in this directory, even when a different version is installed in the
 * target Moodle. Order matters: a parent class must be defined before the file
 * that extends it is required, otherwise the autoloader would fetch the parent
 * from the installed plugin instead.
 */
function load_plugin_classes(): void {
    if (class_exists('aiprovider_n3xtopenrouter\provider', false)) {
        fwrite(STDERR, "The plugin classes are already loaded; cannot verify this checkout.\n");
        exit(2);
    }

    $root = plugin_root();
    $bases = [
        'classes/defaults.php',
        'classes/compat.php',
        'classes/model_list.php',
        'classes/abstract_processor.php',
        'classes/process_generate_text.php',
        'classes/form/action_form.php',
    ];

    foreach ($bases as $relative) {
        require_once("{$root}/{$relative}");
    }

    // Everything else, now that the base classes exist.
    foreach ([glob("{$root}/classes/*.php"), glob("{$root}/classes/form/*.php")] as $group) {
        foreach ($group as $file) {
            require_once($file);
        }
    }
}

/**
 * Turn PHP warnings into reportable findings.
 *
 * A silent "Undefined property" was one of the defects this plugin shipped, so
 * the harness treats warnings as results rather than noise.
 */
function trap_warnings(): void {
    set_error_handler(function (int $no, string $str, string $file, int $line): bool {
        if ($no & (E_WARNING | E_NOTICE | E_DEPRECATED)) {
            $GLOBALS['harness']['warnings'][] = "{$str} ({$file}:{$line})";
            return true;
        }
        return false;
    });
}

/**
 * Assert that two values match.
 *
 * @param string $label What is being checked.
 * @param mixed $expected
 * @param mixed $actual
 */
function check(string $label, mixed $expected, mixed $actual): void {
    $ok = $expected === $actual;
    if (!$ok && is_float($expected) && is_numeric($actual)) {
        $ok = abs($expected - (float) $actual) < 0.0000001;
    }

    if ($ok) {
        $GLOBALS['harness']['pass']++;
        printf("  PASS  %s\n", $label);
        return;
    }

    $GLOBALS['harness']['fail']++;
    printf(
        "  FAIL  %s\n          expected: %s\n          actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

/**
 * Assert that a value is truthy.
 *
 * @param string $label
 * @param mixed $actual
 */
function check_true(string $label, mixed $actual): void {
    check($label, true, (bool) $actual);
}

/**
 * Call a protected or private method.
 *
 * @param object $object
 * @param string $method
 * @param array $args
 * @return mixed
 */
function protected_call(object $object, string $method, array $args = []): mixed {
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
}

/**
 * Print the tally and exit with a status reflecting it.
 *
 * @param string $title
 */
function summary_and_exit(string $title): void {
    $pass = $GLOBALS['harness']['pass'];
    $fail = $GLOBALS['harness']['fail'];
    $warnings = array_unique($GLOBALS['harness']['warnings']);

    printf("\n%s: %d passed, %d failed\n", $title, $pass, $fail);

    if ($warnings) {
        printf("PHP warnings raised (%d):\n", count($warnings));
        foreach ($warnings as $warning) {
            printf("  - %s\n", $warning);
        }
        print("Warnings from core files are upstream issues; see docs/OPERATIONS.md.\n");
    } else {
        print("PHP warnings raised: none\n");
    }

    exit($fail > 0 ? 1 : 0);
}

/**
 * Build a provider instance.
 *
 * @param array $actionconfig Keyed by action class name, as core stores it.
 * @param array $config Provider instance config.
 * @return \aiprovider_n3xtopenrouter\provider
 */
function build_provider(
    array $actionconfig = [],
    array $config = ['apikey' => 'sk-or-verification-only'],
): \aiprovider_n3xtopenrouter\provider {
    return new \aiprovider_n3xtopenrouter\provider(
        enabled: true,
        name: 'OpenRouter',
        config: json_encode($config),
        actionconfig: $actionconfig === [] ? '' : json_encode($actionconfig),
    );
}

/**
 * Build an action config block for one action.
 *
 * @param string $actionclass Fully qualified action class name.
 * @param array $settings
 * @return array
 */
function action_config(string $actionclass, array $settings): array {
    return [$actionclass => ['enabled' => true, 'settings' => $settings]];
}

/**
 * Build a JSON HTTP response.
 *
 * @param string $json
 * @param int $status
 * @return \GuzzleHttp\Psr7\Response
 */
function json_response(string $json, int $status = 200): \GuzzleHttp\Psr7\Response {
    return new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], $json);
}
