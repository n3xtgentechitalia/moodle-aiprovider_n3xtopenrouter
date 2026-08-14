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
 * Check that every language string is defined and every definition is used.
 *
 * Needs no Moodle bootstrap. Usage: php tools/check_lang.php
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$root = dirname(__DIR__);
$component = 'aiprovider_n3xtopenrouter';

$string = [];
eval(preg_replace('/^<\?php/', '', file_get_contents("{$root}/lang/en/{$component}.php")));

$used = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/lang/') || str_contains($path, '/tools/') || str_contains($path, '/.git/')) {
        continue;
    }

    $source = file_get_contents($path);
    $name = basename($path);

    if (preg_match_all('/get_string\(\s*[\'"]([^\'"$]+)[\'"]\s*,\s*[\'"]' . $component . '[\'"]/', $source, $matches)) {
        foreach ($matches[1] as $key) {
            $used[$key] = $name;
        }
    }
    $helppattern = '/addHelpButton\(\s*[\'"][^\'"]+[\'"],\s*[\'"]([^\'"$]+)[\'"],\s*[\'"]'
        . $component . '[\'"]/s';
    if (preg_match_all($helppattern, $source, $matches)) {
        foreach ($matches[1] as $key) {
            $used[$key . '_help'] = $name;
        }
    }
}

// Keys the forms build by interpolating the action name, which the patterns above
// cannot resolve. Kept explicit so a missing translation still gets caught.
$interpolated = [
    'generate_text' => ['model', 'endpoint', 'temperature', 'systeminstruction'],
    'summarise_text' => ['model', 'endpoint', 'temperature', 'systeminstruction', 'maxwords'],
    'generate_image' => ['model', 'endpoint'],
];
foreach ($interpolated as $action => $keys) {
    foreach ($keys as $key) {
        $used["action:{$action}:{$key}"] = 'action_form.php';
        $used["action:{$action}:{$key}_help"] = 'action_form.php';
    }
}

// Resolved by Moodle itself, never through get_string() in plugin code.
foreach ([
    'pluginname',
    'privacy:metadata',
    "privacy:metadata:{$component}:externalpurpose",
    "privacy:metadata:{$component}:model",
    "privacy:metadata:{$component}:prompttext",
    "privacy:metadata:{$component}:aspectratio",
    "privacy:metadata:{$component}:quality",
] as $key) {
    $used[$key] = 'moodle';
}

$missing = array_diff(array_keys($used), array_keys($string));
$orphaned = array_diff(array_keys($string), array_keys($used));

printf("strings defined: %d\n", count($string));
printf("strings used:    %d\n", count($used));
printf("missing (used but not defined): %s\n", $missing ? implode(', ', $missing) : 'none');
printf("orphaned (defined but unused):  %s\n", $orphaned ? implode(', ', $orphaned) : 'none');

$keys = array_keys($string);
$sorted = $keys;
sort($sorted);
$ordered = $keys === $sorted;
printf("alphabetical order: %s\n", $ordered ? 'ok' : 'BROKEN');

exit(($missing || $orphaned || !$ordered) ? 1 : 0);
