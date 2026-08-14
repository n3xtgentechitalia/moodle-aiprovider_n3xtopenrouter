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
use core_ai\aiactions\base;
use GuzzleHttp\Psr7\Response;

/**
 * Test the generate_text processor.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_n3xtopenrouter\process_generate_text
 * @covers     \aiprovider_n3xtopenrouter\abstract_processor
 */
final class process_generate_text_test extends \advanced_testcase {
    use helper_trait;

    /** @var string A successful response, as captured from the API. */
    protected string $responsebodyjson;

    /** @var provider The provider that will process the action. */
    protected provider $provider;

    /** @var base The action to process. */
    protected base $action;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();

        $this->responsebodyjson = file_get_contents(
            self::get_fixture_path('aiprovider_n3xtopenrouter', 'text_request_success.json')
        );
        $this->provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            [
                'model' => 'google/gemini-3.7-flash',
                'endpoint' => defaults::ENDPOINT,
                'temperature' => '0.2',
                'systeminstruction' => 'You are a helpful assistant.',
            ],
        ));
        $this->action = $this->build_action();
    }

    /**
     * Build a generate_text action.
     *
     * @param int $userid The user the action runs as.
     * @return \core_ai\aiactions\generate_text
     */
    private function build_action(int $userid = 1): \core_ai\aiactions\generate_text {
        return new \core_ai\aiactions\generate_text(
            contextid: 1,
            userid: $userid,
            prompttext: 'This is a test prompt',
        );
    }

    /**
     * Test create_request_object.
     */
    public function test_create_request_object(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $request = $this->call_protected($processor, 'create_request_object', ['userhash']);
        $body = json_decode($request->getBody()->getContents());

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('google/gemini-3.7-flash', $body->model);
        $this->assertCount(2, $body->messages);
        $this->assertEquals('system', $body->messages[0]->role);
        $this->assertEquals('You are a helpful assistant.', $body->messages[0]->content);
        $this->assertEquals('user', $body->messages[1]->role);
        $this->assertEquals('This is a test prompt', $body->messages[1]->content);
    }

    /**
     * An empty system instruction means the prompt is sent on its own.
     */
    public function test_create_request_object_without_system_instruction(): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            ['systeminstruction' => ''],
        ));

        $processor = new process_generate_text($provider, $this->action);
        $request = $this->call_protected($processor, 'create_request_object', ['userhash']);
        $body = json_decode($request->getBody()->getContents());

        $this->assertCount(1, $body->messages);
        $this->assertEquals('user', $body->messages[0]->role);
    }

    /**
     * Test the API error response handler.
     */
    public function test_handle_api_error(): void {
        $responses = [
            500 => new Response(500, ['Content-Type' => 'application/json']),
            503 => new Response(503, ['Content-Type' => 'application/json']),
            401 => new Response(
                401,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => ['message' => 'Invalid Authentication']]),
            ),
            402 => new Response(
                402,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => ['message' => 'Insufficient credits']]),
            ),
            429 => new Response(
                429,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => ['message' => 'Rate limit reached for requests']]),
            ),
        ];

        $processor = new process_generate_text($this->provider, $this->action);

        foreach ($responses as $status => $response) {
            $result = $this->call_protected($processor, 'handle_api_error', [$response]);

            $this->assertFalse($result['success']);
            $this->assertEquals($status, $result['errorcode']);
            $this->assertNotEmpty($result['errormessage']);
        }
    }

    /**
     * A flat error message, as some upstream providers return.
     */
    public function test_handle_api_error_with_flat_message(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $response = new Response(
            400,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => 'Bad model id']),
        );

        $result = $this->call_protected($processor, 'handle_api_error', [$response]);

        $this->assertEquals(400, $result['errorcode']);
        $this->assertEquals('Bad model id', $result['errormessage']);
    }

    /**
     * Test the API success response handler.
     */
    public function test_handle_api_success(): void {
        $response = new Response(200, ['Content-Type' => 'application/json'], $this->responsebodyjson);
        $processor = new process_generate_text($this->provider, $this->action);

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertTrue($result['success']);
        $this->assertEquals('chatcmpl-9lkwPWOIiQEvI3nfcGofJcmS5lPYo', $result['id']);
        $this->assertEquals('fp_c4e5b6fa31', $result['fingerprint']);
        $this->assertStringContainsString('Sure, here is some sample text', $result['generatedcontent']);
        $this->assertEquals('stop', $result['finishreason']);
        $this->assertEquals(11, $result['prompttokens']);
        $this->assertEquals(568, $result['completiontokens']);
    }

    /**
     * The model reported is the one that answered, not the one requested.
     *
     * This is the only way to tell what openrouter/auto actually routed to.
     */
    public function test_handle_api_success_reports_the_resolved_model(): void {
        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_text::class,
            ['model' => defaults::MODEL_AUTO],
        ));
        $processor = new process_generate_text($provider, $this->action);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body('Hello.', ['model' => 'anthropic/claude-sonnet-5']),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertEquals('anthropic/claude-sonnet-5', $result['model']);
    }

    /**
     * Optional response fields are genuinely optional.
     *
     * OpenRouter only forwards system_fingerprint for some upstream providers,
     * and reading it unguarded used to raise a PHP warning.
     */
    public function test_handle_api_success_without_optional_fields(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body('Hello.', ['system_fingerprint' => null, 'usage' => null, 'id' => null]),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Hello.', $result['generatedcontent']);
        $this->assertNull($result['fingerprint']);
        $this->assertNull($result['id']);
        $this->assertEquals(0, $result['prompttokens']);
        $this->assertEquals(0, $result['completiontokens']);
    }

    /**
     * A response with no usable content is an error, not an empty success.
     *
     * @param string $body The response body.
     * @dataProvider unusable_body_provider
     */
    public function test_handle_api_success_rejects_unusable_content(string $body): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertFalse($result['success']);
        $this->assertEquals(-1, $result['errorcode']);
        $this->assertNotEmpty($result['errormessage']);
    }

    /**
     * Data provider for test_handle_api_success_rejects_unusable_content.
     *
     * @return array
     */
    public static function unusable_body_provider(): array {
        return [
            'empty body' => [''],
            'not json' => ['<html>gateway error</html>'],
            'no choices' => [json_encode(['id' => 'gen-1', 'choices' => []])],
            'blank content' => [json_encode([
                'id' => 'gen-1',
                'choices' => [['message' => ['content' => '   '], 'finish_reason' => 'stop']],
            ])],
        ];
    }

    /**
     * Test query_ai_api for a successful call.
     */
    public function test_query_ai_api_success(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], $this->responsebodyjson));

        $processor = new process_generate_text($this->provider, $this->action);
        $result = $this->call_protected($processor, 'query_ai_api');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Sure, here is some sample text', $result['generatedcontent']);
    }

    /**
     * Test prepare_response for a success.
     */
    public function test_prepare_response_success(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $response = [
            'success' => true,
            'id' => 'gen-test-1',
            'fingerprint' => 'fp_test',
            'generatedcontent' => 'Sure, here is some sample text',
            'finishreason' => 'stop',
            'prompttokens' => '11',
            'completiontokens' => '568',
            'model' => 'google/gemini-3.7-flash',
        ];

        $result = $this->call_protected($processor, 'prepare_response', [$response]);

        $this->assertInstanceOf(\core_ai\aiactions\responses\response_base::class, $result);
        $this->assertTrue($result->get_success());
        $this->assertEquals('generate_text', $result->get_actionname());
        $this->assertEquals($response['generatedcontent'], $result->get_response_data()['generatedcontent']);
        $this->assertEquals('google/gemini-3.7-flash', $result->get_response_data()['model']);
    }

    /**
     * Test prepare_response for an error.
     */
    public function test_prepare_response_error(): void {
        $processor = new process_generate_text($this->provider, $this->action);
        $response = [
            'success' => false,
            'errorcode' => 500,
            'errormessage' => 'Internal server error.',
        ];

        $result = $this->call_protected($processor, 'prepare_response', [$response]);

        $this->assertInstanceOf(\core_ai\aiactions\responses\response_base::class, $result);
        $this->assertFalse($result->get_success());
        $this->assertEquals(500, $result->get_errorcode());
        $this->assertEquals('Internal server error.', $result->get_errormessage());
    }

    /**
     * Test process.
     */
    public function test_process(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(200, ['Content-Type' => 'application/json'], $this->responsebodyjson));

        $processor = new process_generate_text($this->provider, $this->action);
        $result = $processor->process();

        $this->assertTrue($result->get_success());
        $this->assertEquals('generate_text', $result->get_actionname());
    }

    /**
     * Test process with an API error.
     */
    public function test_process_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(
            401,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => 'Invalid Authentication']]),
        ));

        $processor = new process_generate_text($this->provider, $this->action);
        $result = $processor->process();

        $this->assertFalse($result->get_success());
        $this->assertEquals(401, $result->get_errorcode());
        $this->assertEquals('Invalid Authentication', $result->get_errormessage());
    }

    /**
     * Test process with the per-user rate limiter.
     */
    public function test_process_with_user_rate_limiter(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $clock = $this->mock_clock_with_frozen();

        $provider = $this->build_provider(
            $this->build_actionconfig(\core_ai\aiactions\generate_text::class, ['model' => defaults::MODEL]),
            ['apikey' => 'sk-or-test', 'enableuserratelimit' => 1, 'userratelimit' => 1],
        );

        ['mock' => $mock] = $this->get_mocked_http_client();
        $success = new Response(200, ['Content-Type' => 'application/json'], $this->responsebodyjson);

        // Case 1: within the limit.
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $this->assertTrue($processor->process()->get_success());

        // Case 2: the same user, still inside the hour.
        $clock->bump(HOURSECS - 10);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $result = $processor->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(429, $result->get_errorcode());
        $this->assertEquals('User rate limit exceeded', $result->get_errormessage());

        // Case 3: a different user has their own allowance.
        $this->setUser($user2);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user2->id));
        $this->assertTrue($processor->process()->get_success());

        // Case 4: the window rolls over.
        $clock->bump(11);
        $this->setUser($user1);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $this->assertTrue($processor->process()->get_success());
    }

    /**
     * Test process with the site-wide rate limiter.
     */
    public function test_process_with_global_rate_limiter(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);
        $clock = $this->mock_clock_with_frozen();

        $provider = $this->build_provider(
            $this->build_actionconfig(\core_ai\aiactions\generate_text::class, ['model' => defaults::MODEL]),
            ['apikey' => 'sk-or-test', 'enableglobalratelimit' => 1, 'globalratelimit' => 1],
        );

        ['mock' => $mock] = $this->get_mocked_http_client();
        $success = new Response(200, ['Content-Type' => 'application/json'], $this->responsebodyjson);

        // Case 1: within the limit.
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $this->assertTrue($processor->process()->get_success());

        // Case 2: the site allowance is spent.
        $clock->bump(HOURSECS - 10);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $result = $processor->process();
        $this->assertFalse($result->get_success());
        $this->assertEquals(429, $result->get_errorcode());
        $this->assertEquals('Global rate limit exceeded', $result->get_errormessage());

        // Case 3: a different user does not get a fresh site allowance.
        $this->setUser($user2);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user2->id));
        $this->assertFalse($processor->process()->get_success());

        // Case 4: the window rolls over.
        $clock->bump(11);
        $this->setUser($user1);
        $mock->append($success);
        $processor = new process_generate_text($provider, $this->build_action($user1->id));
        $this->assertTrue($processor->process()->get_success());
    }
}
