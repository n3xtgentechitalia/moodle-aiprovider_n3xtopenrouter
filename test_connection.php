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
 * Admin-only diagnostic page for verifying the OpenRouter provider configuration.
 *
 * Sends one real Generate text request through Moodle's AI subsystem and reports
 * what came back, including which model actually answered.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
require_admin();

$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/ai/provider/n3xtopenrouter/test_connection.php');
$context = context_system::instance();

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('testaiconfiguration', 'aiprovider_n3xtopenrouter'));
$PAGE->set_heading(get_string('testaiconfiguration', 'aiprovider_n3xtopenrouter'));

echo $OUTPUT->header();

// Every load of this page spends real money, so ask first.
if (!$confirm) {
    echo $OUTPUT->confirm(
        get_string('testconnectionconfirm', 'aiprovider_n3xtopenrouter'),
        new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
        new moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
    );
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();

$action = new \core_ai\aiactions\generate_text(
    contextid: $context->id,
    userid: $USER->id,
    prompttext: 'Reply with the single word OK and nothing else.',
);

$manager = \core\di::get(\core_ai\manager::class);
$result = $manager->process_action($action);

if (!$result->get_success()) {
    echo $OUTPUT->notification(
        get_string(
            'testconnectionfailed',
            'aiprovider_n3xtopenrouter',
            (object) [
                'code' => s($result->get_errorcode()),
                'message' => s($result->get_errormessage()),
            ],
        ),
        \core\output\notification::NOTIFY_ERROR,
    );
} else {
    $data = $result->get_response_data();

    echo $OUTPUT->notification(
        get_string('testconnectionsuccess', 'aiprovider_n3xtopenrouter'),
        \core\output\notification::NOTIFY_SUCCESS,
    );

    // The resolved model matters most here: with openrouter/auto it is the only
    // way to see which model the site is really talking to.
    $table = new html_table();
    $table->head = [
        get_string('testconnectiondetail', 'aiprovider_n3xtopenrouter'),
        get_string('testconnectionvalue', 'aiprovider_n3xtopenrouter'),
    ];
    $table->data = [
        [get_string('testconnectionmodel', 'aiprovider_n3xtopenrouter'), s($data['model'] ?? '-')],
        [get_string('testconnectiontokens', 'aiprovider_n3xtopenrouter'), s(
            ($data['prompttokens'] ?? 0) . ' / ' . ($data['completiontokens'] ?? 0)
        )],
        [get_string('testconnectionresponse', 'aiprovider_n3xtopenrouter'), s($data['generatedcontent'] ?? '')],
    ];
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
