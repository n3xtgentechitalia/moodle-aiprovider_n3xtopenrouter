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

namespace aiprovider_n3xtopenrouter;

use aiprovider_n3xtopenrouter\tests\helper_trait;

/**
 * Tests that configured action settings actually reach the request.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_n3xtopenrouter\abstract_processor
 */
final class action_settings_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Build a generate_text action.
     *
     * @return \core_ai\aiactions\generate_text
     */
    private function build_action(): \core_ai\aiactions\generate_text {
        return new \core_ai\aiactions\generate_text(
            contextid: 1,
            userid: 1,
            prompttext: 'This is a test prompt',
        );
    }

    /**
     * Settings stored under the action class name must be honoured.
     *
     * Regression test. Moodle stores action settings keyed by the fully
     * qualified action class name, but this plugin used to look them up by short
     * action name, so nothing an admin configured was ever applied and every
     * request silently used the built-in defaults.
     */
    public function test_settings_keyed_by_action_class_are_applied(): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            [
                'model' => 'anthropic/claude-sonnet-5',
                'endpoint' => 'https://example.invalid/v1/chat/completions',
                'temperature' => '1.5',
                'systeminstruction' => 'Answer in Italian.',
            ],
        ));

        $processor = new process_generate_text($provider, $this->build_action());

        $this->assertEquals('anthropic/claude-sonnet-5', $this->call_protected($processor, 'get_model'));
        $this->assertEquals(
            'https://example.invalid/v1/chat/completions',
            (string) $this->call_protected($processor, 'get_endpoint')
        );
        $this->assertEquals(1.5, $this->call_protected($processor, 'get_temperature'));
        $this->assertEquals('Answer in Italian.', $this->call_protected($processor, 'get_system_instruction'));
    }

    /**
     * The configured model must end up in the request body, not just in a getter.
     */
    public function test_configured_model_reaches_the_request_body(): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            ['model' => 'anthropic/claude-sonnet-5', 'temperature' => '0.7'],
        ));

        $processor = new process_generate_text($provider, $this->build_action());
        $request = $this->call_protected($processor, 'create_request_object', ['userhash']);
        $body = json_decode((string) $request->getBody()->getContents());

        $this->assertEquals('anthropic/claude-sonnet-5', $body->model);
        $this->assertEquals(0.7, $body->temperature);
        $this->assertEquals('userhash', $body->user);
    }

    /**
     * With nothing configured, the documented defaults apply.
     */
    public function test_defaults_apply_when_nothing_is_configured(): void {
        $provider = $this->build_provider();
        $processor = new process_generate_text($provider, $this->build_action());

        $this->assertEquals(defaults::MODEL, $this->call_protected($processor, 'get_model'));
        $this->assertEquals(defaults::ENDPOINT, (string) $this->call_protected($processor, 'get_endpoint'));
        $this->assertEquals((float) defaults::TEMPERATURE, $this->call_protected($processor, 'get_temperature'));
    }

    /**
     * An empty stored value must fall back rather than produce an empty model.
     */
    public function test_empty_settings_fall_back_to_defaults(): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            ['model' => '   ', 'endpoint' => ''],
        ));

        $processor = new process_generate_text($provider, $this->build_action());

        $this->assertEquals(defaults::MODEL, $this->call_protected($processor, 'get_model'));
        $this->assertEquals(defaults::ENDPOINT, (string) $this->call_protected($processor, 'get_endpoint'));
    }

    /**
     * Temperature is clamped to the range the API accepts.
     *
     * @param string $stored The stored setting.
     * @param float $expected The temperature that should be sent.
     * @dataProvider temperature_provider
     */
    public function test_temperature_is_clamped(string $stored, float $expected): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            ['temperature' => $stored],
        ));

        $processor = new process_generate_text($provider, $this->build_action());

        $this->assertEquals($expected, $this->call_protected($processor, 'get_temperature'));
    }

    /**
     * Data provider for test_temperature_is_clamped.
     *
     * @return array
     */
    public static function temperature_provider(): array {
        return [
            'in range' => ['1.2', 1.2],
            'at minimum' => ['0', 0.0],
            'at maximum' => ['2', 2.0],
            'above maximum' => ['7.5', 2.0],
            'below minimum' => ['-3', 0.0],
        ];
    }
}
