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
 * Run Moodle's own installer validation against a built ZIP.
 *
 *   php tools/validate_zip.php --moodle=/path/to/moodle [--zip=/path/to/package.zip]
 *
 * This is the same \core\update\validator that runs when an administrator
 * uploads a plugin through Site administration > Plugins > Install plugins, so a
 * clean run here means a clean run there. With no --zip it builds one from HEAD
 * the way the release workflow does.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$moodle = '';
$zip = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--moodle=')) {
        $moodle = rtrim(substr($arg, strlen('--moodle=')), '/');
    } else if (str_starts_with($arg, '--zip=')) {
        $zip = substr($arg, strlen('--zip='));
    }
}
if ($moodle === '' || !is_file($moodle . '/config.php')) {
    fwrite(STDERR, "Pass --moodle=/path/to/moodle (the directory holding config.php)\n");
    exit(2);
}

$repo = dirname(__DIR__);

if ($zip === '') {
    // No package given: build one from HEAD, exactly as the release workflow does.
    // This needs the repository, so it only works when run from inside it.
    if (!is_file($repo . '/version.php')) {
        fwrite(STDERR, "Run this from the repository, or pass --zip=/path/to/package.zip\n");
        exit(2);
    }
    $declared = file_get_contents($repo . '/version.php');
    if (!preg_match('/\$plugin->component\s*=\s*[\'"]([^\'"]+)/', $declared, $matches)) {
        fwrite(STDERR, "No \$plugin->component in version.php\n");
        exit(2);
    }
    $rootdir = explode('_', $matches[1], 2)[1];

    $zip = tempnam(sys_get_temp_dir(), 'plugzip') . '.zip';
    $built = true;
    $command = sprintf(
        'git -C %s archive --format=zip --prefix=%s/ -o %s HEAD',
        escapeshellarg($repo),
        escapeshellarg($rootdir),
        escapeshellarg($zip)
    );
    exec($command, $out, $status);
    if ($status !== 0) {
        fwrite(STDERR, "Could not build the ZIP from HEAD\n");
        exit(1);
    }
} else if (!is_file($zip)) {
    fwrite(STDERR, "No such file: {$zip}\n");
    exit(2);
}

define('CLI_SCRIPT', true);
require($moodle . '/config.php');
require_once($CFG->libdir . '/classes/update/validator.php');

printf("zip:          %s (%s bytes)%s\n", $zip, number_format(filesize($zip)), $built ? ' [built from HEAD]' : '');

// Unpack it the way the installer does, then validate the result.
$extractdir = make_request_directory();
$packer = get_file_packer('application/zip');
$files = $packer->extract_to_pathname($zip, $extractdir);
if ($files === false) {
    fwrite(STDERR, "The ZIP could not be extracted at all.\n");
    exit(1);
}

// Read what the package declares about itself, so this works on any ZIP.
$roots = [];
foreach (array_keys($files) as $path) {
    $roots[explode('/', $path)[0]] = true;
}
$rootdirs = array_keys($roots);
printf("root dirs:    %s\n", implode(', ', $rootdirs));
if (count($rootdirs) !== 1) {
    printf("\nThe archive must contain exactly one top level directory, named after the plugin.\n");
    printf("A ZIP produced by GitHub's \"Source code\" link has the tag in the name and will be rejected.\n");
}

$component = '(not declared)';
$plugintype = '';
$versionphp = $extractdir . '/' . $rootdirs[0] . '/version.php';
if (is_file($versionphp)
        && preg_match('/\$plugin->component\s*=\s*[\'"]([^\'"]+)/', file_get_contents($versionphp), $matches)) {
    $component = $matches[1];
    $plugintype = explode('_', $component, 2)[0];
}
printf("component:    %s\n", $component);
printf("plugin type:  %s\n\n", $plugintype !== '' ? $plugintype : '(unknown)');

if ($plugintype === '') {
    fwrite(STDERR, "Could not read the component from version.php inside the package.\n");
    exit(1);
}

$validator = \core\update\validator::instance($extractdir, $files);
$validator->assert_plugin_type($plugintype);
$validator->assert_moodle_version($CFG->version);
$result = $validator->execute();

$levels = [
    \core\update\validator::ERROR => 'ERROR',
    \core\update\validator::WARNING => 'WARNING',
    \core\update\validator::INFO => 'info',
    \core\update\validator::DEBUG => 'debug',
];

// Two different questions get answered here, and conflating them is unhelpful.
// Whether the *package* is well formed is about the ZIP. Whether the *site* can
// receive it is about filesystem permissions on that server, and fails identically
// for every plugin when the code tree is not writable by the web server user.
$sitelevel = ['pathwritable', 'unknowntype'];

$packageerrors = 0;
$siteerrors = [];
$warnings = 0;

foreach ($validator->get_messages() as $message) {
    $level = $levels[$message->level] ?? (string) $message->level;
    $info = $message->addinfo;
    if (is_array($info) || is_object($info)) {
        $info = json_encode($info);
    }
    $scope = in_array($message->msgcode, $sitelevel, true) ? 'site' : 'pkg';
    printf("  %-7s %-4s %-28s %s\n", $level, $scope, $message->msgcode, $info === null ? '' : (string) $info);

    if ($level === 'ERROR') {
        if ($scope === 'site') {
            $siteerrors[] = $message->msgcode;
        } else {
            $packageerrors++;
        }
    } else if ($level === 'WARNING') {
        $warnings++;
    }
}

printf("\nrootdir reported by the validator: %s\n", var_export($validator->get_rootdir(), true));
printf("package errors: %d, warnings: %d\n", $packageerrors, $warnings);

if ($packageerrors === 0) {
    print("PACKAGE VALID - the archive itself passes every check Moodle makes of it\n");
} else {
    print("PACKAGE INVALID - fix the archive before publishing it\n");
}

if ($siteerrors) {
    printf("\nThis site cannot receive it: %s\n", implode(', ', $siteerrors));
    if (in_array('pathwritable', $siteerrors, true)) {
        $target = $validator->get_plugintype_location($plugintype);
        printf("  %s is not writable by the user running this script.\n", $target);
        print("  This blocks web-interface installs of EVERY plugin on this site, not\n");
        print("  just this one. It is a common and defensible hardening choice: the code\n");
        print("  tree stays owned by root. Options:\n");
        print("    - install from the shell instead (see tools/deploy.sh), or\n");
        print("    - grant the web server user write access to that directory for as\n");
        print("      long as the upload takes, then take it away again.\n");
    }
    printf("\nMoodle's overall verdict for this site: %s\n", $result ? 'pass' : 'fail');
}

// Exit on the package verdict: that is what this tool is for. A site that cannot
// receive any plugin is a fact about the site, not about the archive.
$result = ($packageerrors === 0);

if ($built) {
    unlink($zip);
}

exit($result ? 0 : 1);
