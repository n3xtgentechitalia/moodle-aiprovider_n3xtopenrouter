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
use GuzzleHttp\Psr7\Response;

/**
 * Test the summarise_text processor.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_n3xtopenrouter\process_summarise_text
 */
final class process_summarise_text_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Build a summarise_text action.
     *
     * @param int $userid The user the action runs as.
     * @return \core_ai\aiactions\summarise_text
     */
    private function build_action(int $userid = 1): \core_ai\aiactions\summarise_text {
        return new \core_ai\aiactions\summarise_text(
            contextid: 1,
            userid: $userid,
            prompttext: 'This is a long piece of text that needs summarising.',
        );
    }

    /**
     * Build a processor with a given word cap.
     *
     * @param int|null $maxwords The cap, or null to leave it unconfigured.
     * @return process_summarise_text
     */
    private function build_processor(?int $maxwords = null): process_summarise_text {
        $settings = ['model' => defaults::MODEL];
        if ($maxwords !== null) {
            $settings['maxwords'] = $maxwords;
        }

        $provider = $this->build_provider(
            $this->build_actionconfig(\core_ai\aiactions\summarise_text::class, $settings)
        );

        return new process_summarise_text($provider, $this->build_action());
    }

    /**
     * A summary longer than the cap is trimmed to it.
     */
    public function test_summary_is_trimmed_to_the_word_cap(): void {
        $processor = $this->build_processor(5);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body('one two three four five six seven eight nine'),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertTrue($result['success']);
        $this->assertEquals('one two three four five', $result['generatedcontent']);
    }

    /**
     * A summary within the cap is left exactly as the model wrote it.
     */
    public function test_summary_within_the_cap_is_unchanged(): void {
        $processor = $this->build_processor(50);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body('A short summary.'),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertEquals('A short summary.', $result['generatedcontent']);
    }

    /**
     * With a cap in force the summary is collapsed to one paragraph.
     */
    public function test_summary_is_collapsed_to_one_paragraph(): void {
        $processor = $this->build_processor(100);
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body("First line.\n\nSecond   line."),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertEquals('First line. Second line.', $result['generatedcontent']);
    }

    /**
     * A cap of zero hands length and formatting back to the model.
     *
     * Earlier releases hard-coded a 500 word single-paragraph cap that could not
     * be turned off, and truncated silently when a model overran it.
     */
    public function test_zero_cap_leaves_the_summary_untouched(): void {
        $processor = $this->build_processor(0);
        $content = "First line.\n\nSecond line.";
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body($content),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertEquals($content, $result['generatedcontent']);
    }

    /**
     * The cap is requested in the prompt as well as enforced on the response.
     */
    public function test_word_cap_is_added_to_the_system_instruction(): void {
        $instruction = $this->call_protected($this->build_processor(120), 'get_system_instruction');

        $this->assertStringContainsString(
            get_string('summaryconstraint', 'aiprovider_n3xtopenrouter', 120),
            $instruction
        );
    }

    /**
     * With no cap, the instruction is left alone.
     */
    public function test_zero_cap_adds_nothing_to_the_system_instruction(): void {
        $instruction = $this->call_protected($this->build_processor(0), 'get_system_instruction');

        $this->assertEquals(\core_ai\aiactions\summarise_text::get_system_instruction(), $instruction);
    }

    /**
     * Unconfigured, the documented default cap applies.
     */
    public function test_default_word_cap_applies(): void {
        $this->assertEquals(defaults::MAXWORDS, $this->call_protected($this->build_processor(), 'get_max_words'));
    }

    /**
     * A negative cap is treated as no cap rather than truncating to nothing.
     */
    public function test_negative_word_cap_is_treated_as_no_cap(): void {
        $this->assertEquals(0, $this->call_protected($this->build_processor(-10), 'get_max_words'));
    }

    /**
     * Test process end to end.
     */
    public function test_process(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_response_body('one two three four five six'),
        ));

        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\summarise_text::class,
            ['model' => defaults::MODEL, 'maxwords' => 3],
        ));
        $processor = new process_summarise_text($provider, $this->build_action());
        $result = $processor->process();

        $this->assertTrue($result->get_success());
        $this->assertEquals('summarise_text', $result->get_actionname());
        $this->assertEquals('one two three', $result->get_response_data()['generatedcontent']);
    }

    /**
     * An API error is reported rather than swallowed.
     */
    public function test_process_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(
            402,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => 'Insufficient credits']]),
        ));

        $processor = $this->build_processor(500);
        $result = $processor->process();

        $this->assertFalse($result->get_success());
        $this->assertEquals(402, $result->get_errorcode());
        $this->assertEquals('Insufficient credits', $result->get_errormessage());
    }
}
