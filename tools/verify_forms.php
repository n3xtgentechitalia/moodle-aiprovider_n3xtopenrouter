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
 * Verify that the settings forms build and render.
 *
 * Usage: php tools/verify_forms.php [--moodle=/path/to/moodle]
 *
 * The admin UI is the part a harness usually cannot reach, so this goes as far as
 * it can without a browser: it checks the QuickForm element types the plugin
 * relies on are available in this environment, runs the provider-instance hook,
 * and renders each action form to HTML checking every expected field is present.
 *
 * Language strings resolve from the *installed* plugin, so rendered labels may
 * appear as [[somestring]] when verifying a checkout that is not installed. That
 * is expected; string coverage is checked by tools/check_lang.php instead.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/verify_common.php');

boot_moodle($argv);
load_plugin_classes();

use aiprovider_n3xtopenrouter\hook_listener;
use aiprovider_n3xtopenrouter\provider;

global $CFG, $PAGE;
$PAGE->set_url('/');
// Surface coding problems, but keep the notices out of the rendered HTML.
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = 0;

require_once($CFG->libdir . '/formslib.php');

print("=== 1. QuickForm elements and modifiers the plugin relies on ===\n");
$probe = new MoodleQuickForm('probe', 'post', '/');
$elements = [
    // The provider form used a plain password element before 1.1.0, on a
    // suspicion that passwordunmask was unavailable in that context. It is not.
    ['passwordunmask', 'apikey', ['size' => 75]],
    ['select', 'modeltemplate', ['a' => 'A', 'custom' => 'Other']],
    ['text', 'custommodel', ['size' => 50]],
    ['textarea', 'systeminstruction', ['rows' => 8, 'cols' => 80]],
    ['static', 'notice', 'some text'],
    ['hidden', 'model', 'x'],
];
foreach ($elements as [$type, $name, $arg]) {
    try {
        $probe->addElement($type, $name, 'Label', $arg);
        check("element type {$type} is available", true, isset($probe->_elementIndex[$name]));
    } catch (\Throwable $e) {
        check("element type {$type} is available", true, get_class($e) . ': ' . $e->getMessage());
    }
}
try {
    $probe->hideIf('custommodel', 'modeltemplate', 'neq', 'custom');
    check_true('hideIf is accepted', true);
} catch (\Throwable $e) {
    check('hideIf is accepted', true, $e->getMessage());
}

print("\n=== 2. The provider instance form, built by the hook ===\n");
$instanceform = new MoodleQuickForm('provider', 'post', '/');
try {
    hook_listener::set_form_definition_for_aiprovider_n3xtopenrouter(
        new \core_ai\hook\after_ai_provider_form_hook(
            mform: $instanceform,
            plugin: 'aiprovider_n3xtopenrouter',
        )
    );
    check_true('the hook listener runs without throwing', true);
    check_true('the API key field is present', isset($instanceform->_elementIndex['apikey']));
    check('the API key is a passwordunmask field', 'passwordunmask', $instanceform->getElement('apikey')->getType());
    check_true('the notice pointing at Actions is present', isset($instanceform->_elementIndex['actionsettingsnotice']));
    // Removed in 1.1.0: OpenRouter ignores the OpenAI organization header.
    check('the organization ID field is gone', false, isset($instanceform->_elementIndex['orgid']));
} catch (\Throwable $e) {
    check('the hook listener runs without throwing', true, get_class($e) . ': ' . $e->getMessage());
}

print("\n=== 3. Each action form renders, with its fields ===\n");
$expected = [
    'generate_text' => [
        \core_ai\aiactions\generate_text::class,
        ['modeltemplate', 'custommodel', 'model', 'endpoint', 'temperature', 'systeminstruction'],
        ['maxwords', 'resolution'],
    ],
    'summarise_text' => [
        \core_ai\aiactions\summarise_text::class,
        ['modeltemplate', 'custommodel', 'model', 'endpoint', 'temperature', 'maxwords', 'systeminstruction'],
        ['resolution'],
    ],
    'generate_image' => [
        \core_ai\aiactions\generate_image::class,
        ['modeltemplate', 'custommodel', 'model', 'endpoint', 'resolution'],
        ['temperature', 'systeminstruction', 'maxwords'],
    ],
];

foreach ($expected as $label => [$actionclass, $present, $absent]) {
    try {
        $form = provider::get_action_settings($actionclass, ['providerid' => 1]);
        ob_start();
        $form->display();
        $html = ob_get_clean();

        check_true("{$label}: renders HTML", strlen($html) > 1000);

        $missing = array_values(array_filter($present, fn($n) => !str_contains($html, 'name="' . $n . '"')));
        check("{$label}: all expected fields rendered", [], $missing);

        $unexpected = array_values(array_filter($absent, fn($n) => str_contains($html, 'name="' . $n . '"')));
        check("{$label}: no fields that do not apply", [], $unexpected);
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        check("{$label}: renders HTML", true, get_class($e) . ': ' . substr($e->getMessage(), 0, 160));
    }
}

summary_and_exit('ADMIN FORMS');
