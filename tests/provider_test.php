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
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Test the OpenRouter provider class.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_n3xtopenrouter\provider
 */
final class provider_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Test get_action_list.
     */
    public function test_get_action_list(): void {
        $actionlist = provider::get_action_list();

        $this->assertIsArray($actionlist);
        $this->assertCount(3, $actionlist);
        $this->assertContains(\core_ai\aiactions\generate_text::class, $actionlist);
        $this->assertContains(\core_ai\aiactions\summarise_text::class, $actionlist);
        $this->assertContains(\core_ai\aiactions\generate_image::class, $actionlist);
        // Not supported yet: explain_text would need its own action settings.
        $this->assertNotContains(\core_ai\aiactions\explain_text::class, $actionlist);
    }

    /**
     * Test generate_userid.
     */
    public function test_generate_userid(): void {
        $provider = $this->build_provider();
        $userid = $provider->generate_userid('1');

        $this->assertIsString($userid);
        $this->assertEquals(64, strlen($userid));
        // Same input, same hash; different input, different hash.
        $this->assertEquals($userid, $provider->generate_userid('1'));
        $this->assertNotEquals($userid, $provider->generate_userid('2'));
    }

    /**
     * Test the headers sent to OpenRouter.
     */
    public function test_add_authentication_headers(): void {
        global $CFG, $SITE;
        $this->resetAfterTest();

        $provider = $this->build_provider(config: ['apikey' => 'sk-or-test']);
        $request = $provider->add_authentication_headers(new Request('POST', ''));

        $this->assertEquals('Bearer sk-or-test', $request->getHeaderLine('Authorization'));
        $this->assertEquals($CFG->wwwroot, $request->getHeaderLine('HTTP-Referer'));
        $this->assertEquals($SITE->fullname, $request->getHeaderLine('X-Title'));
        // OpenRouter has no concept of an OpenAI organization.
        $this->assertFalse($request->hasHeader('OpenAI-Organization'));
    }

    /**
     * Test is_provider_configured.
     */
    public function test_is_provider_configured(): void {
        $this->assertFalse($this->build_provider(config: [])->is_provider_configured());
        $this->assertFalse($this->build_provider(config: ['apikey' => ''])->is_provider_configured());
        $this->assertTrue($this->build_provider(config: ['apikey' => 'sk-or-test'])->is_provider_configured());
    }

    /**
     * Test is_request_allowed.
     */
    public function test_is_request_allowed(): void {
        $this->resetAfterTest();

        $provider = $this->build_provider(config: [
            'apikey' => 'sk-or-test',
            'enableuserratelimit' => 1,
            'userratelimit' => 3,
            'enableglobalratelimit' => 1,
            'globalratelimit' => 5,
        ]);

        $action = new \core_ai\aiactions\generate_text(
            contextid: 1,
            userid: 1,
            prompttext: 'This is a test prompt',
        );

        // Three requests for this user are allowed.
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($provider->is_request_allowed($action));
        }

        // The fourth is not.
        $result = $provider->is_request_allowed($action);
        $this->assertFalse($result['success']);
        $this->assertEquals(429, $result['errorcode']);
        $this->assertEquals('User rate limit exceeded', $result['errormessage']);

        // A different user still has their own allowance, up to the site limit of five.
        $otheruser = new \core_ai\aiactions\generate_text(
            contextid: 1,
            userid: 2,
            prompttext: 'This is a test prompt',
        );
        $this->assertTrue($provider->is_request_allowed($otheruser));
        $this->assertTrue($provider->is_request_allowed($otheruser));

        // Six site-wide requests is one too many.
        $result = $provider->is_request_allowed($otheruser);
        $this->assertFalse($result['success']);
        $this->assertEquals(429, $result['errorcode']);
        $this->assertEquals('Global rate limit exceeded', $result['errormessage']);
    }

    /**
     * Each supported action gets its own settings form.
     */
    public function test_get_action_settings(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');

        $this->assertInstanceOf(
            form\action_generate_text_form::class,
            provider::get_action_settings(\core_ai\aiactions\generate_text::class),
        );
        $this->assertInstanceOf(
            form\action_summarise_text_form::class,
            provider::get_action_settings(\core_ai\aiactions\summarise_text::class),
        );
        $this->assertInstanceOf(
            form\action_generate_image_form::class,
            provider::get_action_settings(\core_ai\aiactions\generate_image::class),
        );
        $this->assertFalse(provider::get_action_settings(\core_ai\aiactions\explain_text::class));
    }

    /**
     * A new provider instance starts with its action settings populated.
     *
     * Without this, core stores an empty settings array, the UI shows no model,
     * and the request quietly falls back to the built-in default instead.
     */
    public function test_get_action_setting_defaults(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [
                [
                    'id' => defaults::MODEL_AUTO,
                    'name' => 'Auto Router',
                    'architecture' => ['output_modalities' => ['text']],
                ],
                [
                    'id' => defaults::MODEL,
                    'name' => 'Default model',
                    'architecture' => ['output_modalities' => ['text']],
                ],
            ],
        ])));

        $settings = provider::get_action_setting_defaults(\core_ai\aiactions\generate_text::class);

        $this->assertEquals(defaults::MODEL, $settings['model']);
        $this->assertEquals(defaults::ENDPOINT, $settings['endpoint']);
        $this->assertEquals(defaults::TEMPERATURE, $settings['temperature']);
        $this->assertNotEmpty($settings['systeminstruction']);
        // Chooser scaffolding must not be stored as if it were a setting.
        $this->assertArrayNotHasKey('modeltemplate', $settings);
        $this->assertArrayNotHasKey('custommodel', $settings);
    }

    /**
     * The image action is seeded from its own catalogue and endpoint.
     */
    public function test_get_action_setting_defaults_for_the_image_action(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/');

        // Seeded so the form needs no network.
        $this->seed_image_catalogue([
            defaults::IMAGE_MODEL => ['name' => 'Test image model', 'params' => ['aspect_ratio' => [], 'n' => []]],
        ]);

        $settings = provider::get_action_setting_defaults(\core_ai\aiactions\generate_image::class);

        $this->assertEquals(defaults::IMAGE_MODEL, $settings['model']);
        $this->assertEquals(defaults::IMAGE_ENDPOINT, $settings['endpoint']);
        $this->assertEquals(defaults::IMAGE_RESOLUTION, $settings['resolution']);
        // Image generation has no temperature or system instruction.
        $this->assertArrayNotHasKey('temperature', $settings);
        $this->assertArrayNotHasKey('systeminstruction', $settings);
        $this->assertArrayNotHasKey('capabilitynotice', $settings);
    }

    /**
     * An unsupported action has no defaults to seed.
     */
    public function test_get_action_setting_defaults_for_unsupported_action(): void {
        $this->assertSame([], provider::get_action_setting_defaults(\core_ai\aiactions\explain_text::class));
    }
}
