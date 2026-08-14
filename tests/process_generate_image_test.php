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
 * Test the generate_image processor.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \aiprovider_n3xtopenrouter\process_generate_image
 */
final class process_generate_image_test extends \advanced_testcase {
    use helper_trait;

    /** @var string A valid 1x1 PNG, so the watermarker has something real to open. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Capabilities matching a Google image model: no quality, n capped at 1.
     *
     * @return array
     */
    private static function google_caps(): array {
        return [
            'resolution' => ['type' => 'enum', 'values' => ['512', '1K', '2K', '4K']],
            'aspect_ratio' => ['type' => 'enum', 'values' => ['1:1', '2:3', '3:2', '3:4', '4:3', '9:16', '16:9']],
            'n' => ['type' => 'range', 'min' => 1, 'max' => 1],
        ];
    }

    /**
     * Capabilities matching Recraft: no 3:2, no quality, no resolution, n up to 6.
     *
     * @return array
     */
    private static function recraft_caps(): array {
        return [
            'aspect_ratio' => ['type' => 'enum', 'values' => ['1:1', '4:3', '3:4', '16:9', '9:16', 'auto']],
            'n' => ['type' => 'range', 'min' => 1, 'max' => 6],
        ];
    }

    /**
     * Capabilities matching a GPT image model: quality yes, resolution no.
     *
     * @return array
     */
    private static function gptimage_caps(): array {
        return [
            'aspect_ratio' => ['type' => 'enum', 'values' => ['1:1', '3:2', '2:3', '16:9', '9:16', 'auto']],
            'quality' => ['type' => 'enum', 'values' => ['auto', 'low', 'medium', 'high']],
            'n' => ['type' => 'range', 'min' => 1, 'max' => 10],
        ];
    }

    /**
     * Build an image action.
     *
     * @param string $aspectratio square, landscape or portrait.
     * @param string $quality standard or hd.
     * @param string $style The requested visual style.
     * @param int $numimages How many images the caller asked for.
     * @param int $userid The user the action runs as.
     * @return \core_ai\aiactions\generate_image
     */
    private function build_action(
        string $aspectratio = 'square',
        string $quality = 'hd',
        string $style = 'vivid',
        int $numimages = 1,
        int $userid = 1,
    ): \core_ai\aiactions\generate_image {
        return new \core_ai\aiactions\generate_image(
            contextid: 1,
            userid: $userid,
            prompttext: 'A cat wearing a hat',
            quality: $quality,
            aspectratio: $aspectratio,
            numimages: $numimages,
            style: $style,
        );
    }

    /**
     * Build the processor with a seeded capability catalogue.
     *
     * @param array $caps The capabilities to advertise for the model.
     * @param \core_ai\aiactions\generate_image|null $action The action to process.
     * @param array $settings Action settings.
     * @return process_generate_image
     */
    private function build_processor(
        array $caps,
        ?\core_ai\aiactions\generate_image $action = null,
        array $settings = [],
    ): process_generate_image {
        $model = $settings['model'] ?? defaults::IMAGE_MODEL;
        $this->seed_image_catalogue([$model => ['name' => 'Test image model', 'params' => $caps]]);

        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_image::class,
            $settings + ['model' => $model],
        ));

        return new process_generate_image($provider, $action ?? $this->build_action());
    }

    /**
     * Decode the body the processor would send.
     *
     * @param process_generate_image $processor
     * @return \stdClass
     */
    private function sent_body(process_generate_image $processor): \stdClass {
        $request = $this->call_protected($processor, 'create_request_object', ['userhash']);
        return json_decode((string) $request->getBody()->getContents());
    }

    /**
     * The image action targets the image endpoint, not the chat one.
     */
    public function test_image_action_uses_the_image_endpoint(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(self::google_caps());

        $this->assertEquals(defaults::IMAGE_ENDPOINT, (string) $this->call_protected($processor, 'get_endpoint'));
        $this->assertEquals(defaults::IMAGE_MODEL, $this->call_protected($processor, 'get_model'));
        $this->assertNotEquals(defaults::ENDPOINT, defaults::IMAGE_ENDPOINT);
    }

    /**
     * Only ever one image is requested.
     *
     * response_generate_image carries exactly one draft file, so asking for more
     * would bill for images Moodle discards.
     */
    public function test_only_one_image_is_requested(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::gptimage_caps(),
            $this->build_action(numimages: 8),
        );

        $this->assertEquals(1, $this->sent_body($processor)->n);
    }

    /**
     * Moodle's aspect ratio names become ratios the endpoint accepts.
     *
     * @param string $moodleratio The ratio the action carries.
     * @param string $expected The ratio that should be sent.
     * @dataProvider aspect_ratio_provider
     */
    public function test_aspect_ratio_is_mapped(string $moodleratio, string $expected): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(aspectratio: $moodleratio),
        );

        $this->assertEquals($expected, $this->sent_body($processor)->aspect_ratio);
    }

    /**
     * Data provider for test_aspect_ratio_is_mapped.
     *
     * @return array
     */
    public static function aspect_ratio_provider(): array {
        return [
            'square' => ['square', '1:1'],
            'landscape' => ['landscape', '3:2'],
            'portrait' => ['portrait', '2:3'],
        ];
    }

    /**
     * A ratio the model rejects falls back to the next best it accepts.
     *
     * Recraft accepts no 3:2, so a landscape request must become 16:9 rather
     * than failing the generation.
     */
    public function test_aspect_ratio_falls_back_to_a_supported_value(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::recraft_caps(),
            $this->build_action(aspectratio: 'landscape'),
        );

        $this->assertEquals('16:9', $this->sent_body($processor)->aspect_ratio);
    }

    /**
     * Quality is sent only to models that accept it.
     */
    public function test_quality_is_sent_only_when_supported(): void {
        $this->resetAfterTest();

        $supported = $this->sent_body($this->build_processor(
            self::gptimage_caps(),
            $this->build_action(quality: 'hd'),
        ));
        $this->assertEquals('high', $supported->quality);

        $unsupported = $this->sent_body($this->build_processor(
            self::google_caps(),
            $this->build_action(quality: 'hd'),
        ));
        $this->assertObjectNotHasProperty('quality', $unsupported);
    }

    /**
     * A quality Moodle did not document falls back rather than failing.
     */
    public function test_unknown_quality_falls_back_to_auto(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::gptimage_caps(),
            $this->build_action(quality: 'ultra'),
        );

        $this->assertEquals(defaults::IMAGE_QUALITY_FALLBACK, $this->sent_body($processor)->quality);
    }

    /**
     * Resolution is sent when the model accepts the configured tier.
     */
    public function test_resolution_is_sent_when_the_tier_is_accepted(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::google_caps(),
            settings: ['resolution' => '2K'],
        );

        $this->assertEquals('2K', $this->sent_body($processor)->resolution);
    }

    /**
     * An unsupported tier is omitted rather than silently swapped.
     */
    public function test_unsupported_resolution_tier_is_omitted(): void {
        $this->resetAfterTest();

        // Seedream accepts only 2K and 4K.
        $caps = self::google_caps();
        $caps['resolution'] = ['type' => 'enum', 'values' => ['2K', '4K']];

        $processor = $this->build_processor($caps, settings: ['resolution' => '1K']);
        $body = $this->sent_body($processor);

        $this->assertObjectNotHasProperty('resolution', $body);
        // The rest of the request is unaffected.
        $this->assertEquals('1:1', $body->aspect_ratio);
    }

    /**
     * A model that accepts no optional parameters gets none.
     */
    public function test_a_model_with_no_optional_parameters_gets_none(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor([]);
        $body = $this->sent_body($processor);

        $this->assertEquals(defaults::IMAGE_MODEL, $body->model);
        $this->assertNotEmpty($body->prompt);
        foreach (['n', 'aspect_ratio', 'quality', 'resolution'] as $param) {
            $this->assertObjectNotHasProperty($param, $body);
        }
    }

    /**
     * An unknown model gets the conservative assumed capabilities.
     */
    public function test_unknown_model_uses_assumed_capabilities(): void {
        $this->resetAfterTest();
        $this->seed_image_catalogue(['some/known-model' => ['name' => 'Known', 'params' => []]]);

        $provider = $this->build_provider($this->build_actionconfig(
            \core_ai\aiactions\generate_image::class,
            ['model' => 'someone/hand-typed-model'],
        ));
        $body = $this->sent_body(new process_generate_image($provider, $this->build_action()));

        $this->assertEquals('someone/hand-typed-model', $body->model);
        $this->assertEquals('1:1', $body->aspect_ratio);
        $this->assertEquals(1, $body->n);
        // Quality is not assumed: only 7 of 43 models accept it.
        $this->assertObjectNotHasProperty('quality', $body);
    }

    /**
     * The style Moodle asked for is expressed in the prompt.
     *
     * The endpoint has no style parameter and silently drops one.
     */
    public function test_style_is_folded_into_the_prompt(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(style: 'vivid'),
        );
        $body = $this->sent_body($processor);

        $this->assertStringContainsString('A cat wearing a hat', $body->prompt);
        $this->assertStringContainsString('vivid', $body->prompt);
        $this->assertObjectNotHasProperty('style', $body);
    }

    /**
     * No style means the prompt is sent untouched.
     */
    public function test_no_style_leaves_the_prompt_alone(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(style: ''),
        );

        $this->assertEquals('A cat wearing a hat', $this->sent_body($processor)->prompt);
    }

    /**
     * A successful response yields the image payload and its format.
     */
    public function test_handle_api_success(): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(self::google_caps());
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_image_response_body('aGVsbG8=', 'image/png', 'A very fine cat in a hat'),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertTrue($result['success']);
        $this->assertEquals('aGVsbG8=', $result['b64json']);
        $this->assertEquals('png', $result['output_format']);
        $this->assertEquals('A very fine cat in a hat', $result['revisedprompt']);
        // The endpoint returns bytes inline; there is nothing hosted to link to.
        $this->assertNull($result['sourceurl']);
        $this->assertEquals(defaults::IMAGE_MODEL, $result['model']);
    }

    /**
     * The reported media type decides the file extension.
     *
     * @param string|null $mediatype The media type in the response.
     * @param string $expected The extension that should be used.
     * @dataProvider media_type_provider
     */
    public function test_media_type_maps_to_a_format(?string $mediatype, string $expected): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(self::google_caps());
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_image_response_body('aGVsbG8=', $mediatype),
        );

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertEquals($expected, $result['output_format']);
    }

    /**
     * Data provider for test_media_type_maps_to_a_format.
     *
     * @return array
     */
    public static function media_type_provider(): array {
        return [
            'png' => ['image/png', 'png'],
            'jpeg' => ['image/jpeg', 'jpg'],
            'webp' => ['image/webp', 'webp'],
            'svg from a vector model' => ['image/svg+xml', 'svg'],
            'absent' => [null, 'png'],
        ];
    }

    /**
     * A response carrying no image is an error, not an empty success.
     *
     * @param string $body The response body.
     * @dataProvider unusable_body_provider
     */
    public function test_response_without_an_image_is_an_error(string $body): void {
        $this->resetAfterTest();
        $processor = $this->build_processor(self::google_caps());
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);

        $result = $this->call_protected($processor, 'handle_api_success', [$response]);

        $this->assertFalse($result['success']);
        $this->assertEquals(-1, $result['errorcode']);
        $this->assertNotEmpty($result['errormessage']);
    }

    /**
     * Data provider for test_response_without_an_image_is_an_error.
     *
     * @return array
     */
    public static function unusable_body_provider(): array {
        return [
            'empty body' => [''],
            'not json' => ['<html>gateway error</html>'],
            'empty data array' => [json_encode(['created' => 1, 'data' => []])],
            'entry without b64_json' => [json_encode(['data' => [['media_type' => 'image/png']]])],
            'blank b64_json' => [json_encode(['data' => [['b64_json' => '   ']]])],
        ];
    }

    /**
     * Vector output is stored even though it cannot be watermarked.
     */
    public function test_svg_is_stored_without_a_watermark(): void {
        $this->resetAfterTest();
        $this->setUser($user = $this->getDataGenerator()->create_user());

        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(userid: (int) $user->id),
        );
        $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');

        $file = $this->call_protected($processor, 'create_file_from_response', [
            (int) $user->id,
            ['b64json' => $svg, 'output_format' => 'svg'],
        ]);

        $this->assertInstanceOf(\stored_file::class, $file);
        $this->assertStringEndsWith('.svg', $file->get_filename());
    }

    /**
     * End to end: a generated image comes back as a draft file.
     */
    public function test_process_returns_a_draft_file(): void {
        $this->resetAfterTest();
        $this->setUser($user = $this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(
            200,
            ['Content-Type' => 'application/json'],
            $this->build_image_response_body(self::PNG_1X1, 'image/png'),
        ));

        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(userid: (int) $user->id),
        );
        $result = $processor->process();

        $this->assertTrue($result->get_success());
        $this->assertEquals('generate_image', $result->get_actionname());

        $data = $result->get_response_data();
        $this->assertInstanceOf(\stored_file::class, $data['draftfile']);
        $this->assertStringEndsWith('.png', $data['draftfile']->get_filename());
        $this->assertEquals(defaults::IMAGE_MODEL, $data['model']);
    }

    /**
     * An API failure is reported and no file is invented.
     */
    public function test_process_error(): void {
        $this->resetAfterTest();
        $this->setUser($user = $this->getDataGenerator()->create_user());

        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(
            402,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => 'Insufficient credits']]),
        ));

        $processor = $this->build_processor(
            self::google_caps(),
            $this->build_action(userid: (int) $user->id),
        );
        $result = $processor->process();

        $this->assertFalse($result->get_success());
        $this->assertEquals(402, $result->get_errorcode());
        $this->assertEquals('Insufficient credits', $result->get_errormessage());
        $this->assertNull($result->get_response_data()['draftfile']);
    }
}
