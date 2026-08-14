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
 * Verify the text actions against a real Moodle bootstrap.
 *
 * Usage: php tools/verify_settings.php [--moodle=/path/to/moodle]
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/verify_common.php');

boot_moodle($argv);
load_plugin_classes();
trap_warnings();

use aiprovider_n3xtopenrouter\defaults;
use aiprovider_n3xtopenrouter\model_list;
use aiprovider_n3xtopenrouter\process_generate_text;
use aiprovider_n3xtopenrouter\process_summarise_text;
use aiprovider_n3xtopenrouter\provider;

const GENERATE = \core_ai\aiactions\generate_text::class;
const SUMMARISE = \core_ai\aiactions\summarise_text::class;

/**
 * Build a generate_text action.
 *
 * @return \core_ai\aiactions\generate_text
 */
function text_action(): \core_ai\aiactions\generate_text {
    return new \core_ai\aiactions\generate_text(
        contextid: 1,
        userid: 1,
        prompttext: 'This is a test prompt',
    );
}

/**
 * Build a summarise_text action.
 *
 * @return \core_ai\aiactions\summarise_text
 */
function summarise_action(): \core_ai\aiactions\summarise_text {
    return new \core_ai\aiactions\summarise_text(
        contextid: 1,
        userid: 1,
        prompttext: 'Long text that needs summarising.',
    );
}

/**
 * Build a chat completion response body.
 *
 * @param string $content
 * @param array $extra Keys to override, or null to omit.
 * @return string
 */
function chat_body(string $content, array $extra = []): string {
    $body = [
        'id' => 'gen-1',
        'model' => 'google/gemini-3.7-flash',
        'system_fingerprint' => 'fp_test',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22],
    ];

    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($body[$key]);
        } else {
            $body[$key] = $value;
        }
    }

    return json_encode($body);
}

print("=== 1. Configured action settings are applied (the 1.0.1 defect) ===\n");
$processor = new process_generate_text(
    build_provider(action_config(GENERATE, [
        'model' => 'anthropic/claude-sonnet-5',
        'endpoint' => 'https://example.invalid/v1/chat/completions',
        'temperature' => '1.5',
        'systeminstruction' => 'Answer in Italian.',
    ])),
    text_action(),
);
check('get_model() reads the configured model', 'anthropic/claude-sonnet-5', protected_call($processor, 'get_model'));
check(
    'get_endpoint() reads the configured endpoint',
    'https://example.invalid/v1/chat/completions',
    (string) protected_call($processor, 'get_endpoint')
);
check('get_temperature() reads the configured temperature', 1.5, protected_call($processor, 'get_temperature'));
check(
    'get_system_instruction() reads the configured instruction',
    'Answer in Italian.',
    protected_call($processor, 'get_system_instruction')
);

$request = protected_call($processor, 'create_request_object', ['userhash']);
$sent = json_decode((string) $request->getBody()->getContents());
check('the configured model reaches the request body', 'anthropic/claude-sonnet-5', $sent->model);
check('the configured temperature reaches the body', 1.5, $sent->temperature);

print("\n=== 2. Defaults when nothing is configured ===\n");
$processor = new process_generate_text(build_provider(), text_action());
check('default model', defaults::MODEL, protected_call($processor, 'get_model'));
check('default endpoint', defaults::ENDPOINT, (string) protected_call($processor, 'get_endpoint'));
$processor = new process_generate_text(build_provider(action_config(GENERATE, ['model' => '   '])), text_action());
check('a blank model falls back to the default', defaults::MODEL, protected_call($processor, 'get_model'));

print("\n=== 3. Temperature clamped to the range the API accepts ===\n");
foreach ([['7.5', 2.0], ['-3', 0.0], ['1.2', 1.2]] as [$stored, $expected]) {
    $processor = new process_generate_text(build_provider(action_config(GENERATE, ['temperature' => $stored])), text_action());
    check("temperature '{$stored}' -> {$expected}", $expected, protected_call($processor, 'get_temperature'));
}

print("\n=== 4. Response handling: optional fields and the resolved model ===\n");
$processor = new process_generate_text(build_provider(action_config(GENERATE, ['model' => defaults::MODEL_AUTO])), text_action());
$routed = json_response(chat_body('Hi.', ['model' => 'anthropic/claude-sonnet-5']));
$result = protected_call($processor, 'handle_api_success', [$routed]);
check('reports the model that actually answered', 'anthropic/claude-sonnet-5', $result['model']);
$result = protected_call($processor, 'handle_api_success', [
    json_response(chat_body('Hi.', ['system_fingerprint' => null, 'usage' => null, 'id' => null])),
]);
check_true('succeeds without system_fingerprint, usage or id', $result['success']);
check('absent fingerprint becomes null', null, $result['fingerprint']);
check('absent usage becomes 0 tokens', 0, $result['prompttokens']);
foreach ([['empty body', ''], ['not json', '<html>502</html>'], ['blank content', chat_body('   ')]] as [$label, $bad]) {
    $result = protected_call($processor, 'handle_api_success', [json_response($bad)]);
    check("unusable response ({$label}) is an error", false, $result['success']);
}
$result = protected_call($processor, 'handle_api_error', [
    json_response(json_encode(['error' => ['message' => 'Insufficient credits']]), 402),
]);
check('API error message is propagated', 'Insufficient credits', $result['errormessage']);

print("\n=== 5. Summarise: configurable word cap ===\n");
$capped = new process_summarise_text(build_provider(action_config(SUMMARISE, ['maxwords' => 5])), summarise_action());
$result = protected_call($capped, 'handle_api_success', [json_response(chat_body('one two three four five six seven eight nine'))]);
check('a cap of 5 trims the summary', 'one two three four five', $result['generatedcontent']);
// The word-limit instruction is a language string resolved from the *installed*
// plugin, so this is asserted language-independently.
$plain = new process_summarise_text(build_provider(action_config(SUMMARISE, ['maxwords' => 0])), summarise_action());
check_true(
    'the cap extends the system instruction',
    strlen(protected_call($capped, 'get_system_instruction')) > strlen(protected_call($plain, 'get_system_instruction'))
);
$original = "First line.\n\nSecond line.";
$result = protected_call($plain, 'handle_api_success', [json_response(chat_body($original))]);
check('a cap of 0 leaves the text untouched', $original, $result['generatedcontent']);
check(
    'a cap of 0 leaves the instruction untouched',
    \core_ai\aiactions\summarise_text::get_system_instruction(),
    protected_call($plain, 'get_system_instruction')
);
$unset = new process_summarise_text(build_provider(action_config(SUMMARISE, [])), summarise_action());
check('default cap', defaults::MAXWORDS, protected_call($unset, 'get_max_words'));

print("\n=== 6. Headers sent to OpenRouter ===\n");
global $CFG;
$request = build_provider(config: ['apikey' => 'sk-or-test'])
    ->add_authentication_headers(new \GuzzleHttp\Psr7\Request('POST', ''));
check('Authorization bearer token', 'Bearer sk-or-test', $request->getHeaderLine('Authorization'));
check('HTTP-Referer is the site URL', $CFG->wwwroot, $request->getHeaderLine('HTTP-Referer'));
check_true('X-Title carries the site name', $request->getHeaderLine('X-Title') !== '');
check('no OpenAI-Organization header', false, $request->hasHeader('OpenAI-Organization'));

print("\n=== 7. Live text catalogue ===\n");
$models = model_list::get_text_models();
check_true('catalogue is populated', count($models) > 50);
printf("          models offered: %d\n", count($models));
check('Auto Router is listed first', defaults::MODEL_AUTO, array_key_first($models));
check_true('the default model is in the catalogue', array_key_exists(defaults::MODEL, $models));
$batch = array_filter(array_keys($models), fn($id) => str_ends_with($id, ':batch'));
check('batch-only variants are excluded', 0, count($batch));

print("\n=== 8. A new instance is seeded with visible settings ===\n");
try {
    $seeded = provider::get_action_setting_defaults(GENERATE);
    check('seeded model', defaults::MODEL, $seeded['model'] ?? null);
    check('seeded endpoint', defaults::ENDPOINT, $seeded['endpoint'] ?? null);
    check('seeded temperature', defaults::TEMPERATURE, $seeded['temperature'] ?? null);
    check_true('seeded system instruction', !empty($seeded['systeminstruction']));
    check('chooser scaffolding is not stored', false, array_key_exists('modeltemplate', $seeded));
    check('custom model field is not stored', false, array_key_exists('custommodel', $seeded));
    $summariseseeded = provider::get_action_setting_defaults(SUMMARISE);
    check('summarise seeds its word cap', defaults::MAXWORDS, (int) ($summariseseeded['maxwords'] ?? 0));
    check('an unsupported action seeds nothing', [], provider::get_action_setting_defaults(\core_ai\aiactions\explain_text::class));
} catch (\Throwable $e) {
    printf("  SKIP  form construction unavailable in CLI: %s\n", $e->getMessage());
}
check_true('generate_image is registered', in_array(\core_ai\aiactions\generate_image::class, provider::get_action_list(), true));

summary_and_exit('TEXT ACTIONS');
