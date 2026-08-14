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
 * Verify image generation against a real Moodle bootstrap and the live API.
 *
 * Usage: php tools/verify_image.php [--moodle=/path/to/moodle] [--offline]
 *
 * Section 5 posts each built request body to OpenRouter with no API key. The
 * endpoint validates the body before it authenticates, so HTTP 401 means the
 * request shape was accepted and HTTP 400 means it was rejected. Nothing is
 * generated and nothing is billed. Pass --offline to skip it.
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
use aiprovider_n3xtopenrouter\process_generate_image;

const IMAGE = \core_ai\aiactions\generate_image::class;

$offline = in_array('--offline', $argv, true);

/**
 * Build an image action.
 *
 * @param string $aspectratio square, landscape or portrait.
 * @param string $quality standard or hd.
 * @param string $style Requested visual style.
 * @param int $numimages How many images the caller asks for.
 * @return \core_ai\aiactions\generate_image
 */
function image_action(
    string $aspectratio = 'square',
    string $quality = 'hd',
    string $style = 'vivid',
    int $numimages = 1,
): \core_ai\aiactions\generate_image {
    return new \core_ai\aiactions\generate_image(
        contextid: 1,
        userid: 1,
        prompttext: 'A cat wearing a hat',
        quality: $quality,
        aspectratio: $aspectratio,
        numimages: $numimages,
        style: $style,
    );
}

/**
 * Build the request body the processor would send.
 *
 * @param array $settings Action settings.
 * @param \core_ai\aiactions\generate_image $action
 * @return \stdClass
 */
function image_body(array $settings, \core_ai\aiactions\generate_image $action): \stdClass {
    $processor = new process_generate_image(build_provider(action_config(IMAGE, $settings)), $action);
    $request = protected_call($processor, 'create_request_object', ['userhash']);

    return json_decode((string) $request->getBody()->getContents());
}

/**
 * Build an image generation response body.
 *
 * @param string $b64json
 * @param string|null $mediatype
 * @return string
 */
function image_response(string $b64json = 'aGVsbG8=', ?string $mediatype = 'image/png'): string {
    $entry = ['b64_json' => $b64json];
    if ($mediatype !== null) {
        $entry['media_type'] = $mediatype;
    }

    return json_encode(['created' => 1, 'data' => [$entry], 'usage' => ['total_tokens' => 1]]);
}

$processor = new process_generate_image(build_provider(action_config(IMAGE, [])), image_action());

print("=== 1. The image action targets the image endpoint ===\n");
check('default endpoint is /api/v1/images', defaults::IMAGE_ENDPOINT, (string) protected_call($processor, 'get_endpoint'));
check('default image model', defaults::IMAGE_MODEL, protected_call($processor, 'get_model'));
check_true('image endpoint differs from the chat endpoint', defaults::IMAGE_ENDPOINT !== defaults::ENDPOINT);

print("\n=== 2. Mapping from Moodle's parameters, against live capabilities ===\n");
foreach ([['square', '1:1'], ['landscape', '3:2'], ['portrait', '2:3']] as [$ratio, $expected]) {
    check("aspectratio '{$ratio}' -> {$expected}", $expected, image_body([], image_action($ratio))->aspect_ratio ?? null);
}
$body = image_body([], image_action(numimages: 8));
check('n is always 1, even when 8 are requested', 1, $body->n ?? null);
$plain = image_body([], image_action('square', 'hd', ''));
// The style suffix is a language string resolved from the *installed* plugin, so
// this is asserted language-independently.
check_true('style extends the prompt', strlen($body->prompt) > strlen($plain->prompt));
check('without a style the prompt is untouched', 'A cat wearing a hat', $plain->prompt);
check('no style parameter in the body', false, property_exists($body, 'style'));
check('quality omitted: the Google model does not accept it', false, property_exists($body, 'quality'));
check('resolution sent (1K is accepted)', '1K', $body->resolution ?? null);

print("\n=== 3. Filtering against other models' real capabilities ===\n");
$cases = [
    'recraft/recraft-v4' => ['landscape', 'aspect_ratio', '16:9'],
    'openai/gpt-image-2' => ['landscape', 'quality', 'high'],
    'bytedance-seed/seedream-5-0-lite' => ['square', 'resolution', null],
    'krea/krea-2-large' => ['square', 'n', null],
];
foreach ($cases as $model => [$ratio, $param, $expected]) {
    $body = image_body(['model' => $model, 'resolution' => '1K'], image_action($ratio));
    check("{$model} -> {$param}", $expected, $body->$param ?? null);
}

print("\n=== 4. Response handling ===\n");
$result = protected_call($processor, 'handle_api_success', [json_response(image_response())]);
check_true('success', $result['success']);
check('base64 payload extracted', 'aGVsbG8=', $result['b64json']);
check('sourceurl is null: the image is inline', null, $result['sourceurl']);
foreach ([['image/jpeg', 'jpg'], ['image/webp', 'webp'], ['image/svg+xml', 'svg'], [null, 'png']] as [$mediatype, $extension]) {
    $result = protected_call($processor, 'handle_api_success', [json_response(image_response('aGVsbG8=', $mediatype))]);
    check('media_type ' . var_export($mediatype, true) . " -> .{$extension}", $extension, $result['output_format']);
}
$unusable = [
    'empty body' => '',
    'not json' => '<html>502</html>',
    'empty data array' => json_encode(['data' => []]),
    'no b64_json' => json_encode(['data' => [['media_type' => 'image/png']]]),
];
foreach ($unusable as $label => $bad) {
    $result = protected_call($processor, 'handle_api_success', [json_response($bad)]);
    check("response without an image ({$label}) is an error", false, $result['success']);
}

print("\n=== 5. The built body passes OpenRouter's real validation ===\n");
if ($offline) {
    print("  SKIP  --offline given\n");
} else {
    print("        no API key: 401 means the schema was accepted, 400 means rejected\n");
    $models = [
        'google/gemini-3.1-flash-image',
        'openai/gpt-image-2',
        'recraft/recraft-v4',
        'krea/krea-2-large',
        'bytedance-seed/seedream-5-0-lite',
        'x-ai/grok-imagine-image-2.0',
    ];
    foreach ($models as $model) {
        foreach (['square', 'landscape', 'portrait'] as $ratio) {
            $body = image_body(['model' => $model, 'resolution' => '1K'], image_action($ratio));

            $curl = curl_init(defaults::IMAGE_ENDPOINT);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
            ]);
            $out = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            check(sprintf('%-34s %-9s schema accepted', $model, $ratio), 401, $status);
            if ($status === 400) {
                $decoded = json_decode((string) $out, true);
                printf("          rejected: %s\n", substr((string) ($decoded['error']['message'] ?? $out), 0, 200));
            }
        }
    }
}

print("\n=== 6. Watermarking, on temporary files only ===\n");
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$pngpath = sys_get_temp_dir() . '/or-verify-' . getmypid() . '.png';
file_put_contents($pngpath, $png);
protected_call($processor, 'watermark', [$pngpath, 'png']);
check_true('the PNG survives watermarking', is_file($pngpath) && filesize($pngpath) > 0);
$info = @getimagesize($pngpath);
check_true('it is still a valid PNG', is_array($info) && $info[2] === IMAGETYPE_PNG);
@unlink($pngpath);

$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
$svgpath = sys_get_temp_dir() . '/or-verify-' . getmypid() . '.svg';
file_put_contents($svgpath, $svg);
protected_call($processor, 'watermark', [$svgpath, 'svg']);
check('vector output is left untouched', $svg, file_get_contents($svgpath));
@unlink($svgpath);
check_true('GD is available for watermarking', function_exists('imagecreatefromstring'));

summary_and_exit('IMAGE ACTION');
