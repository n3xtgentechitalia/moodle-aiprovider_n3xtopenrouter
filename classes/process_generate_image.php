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

use core_ai\ai_image;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Process an image generation action against OpenRouter.
 *
 * Uses OpenRouter's unified image endpoint, which is not the chat endpoint the
 * text actions use and does not take an OpenAI-style pixel `size`. It takes a
 * semantic `aspect_ratio`, which is a better fit for what Moodle asks for.
 *
 * The parameters a model accepts vary sharply - quality is accepted by 7 of the
 * 43 image models, resolution by 19 - and sending one a model does not accept is
 * an error, so every optional parameter is checked against the model's published
 * capabilities before it goes anywhere near the request.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_image extends abstract_processor {
    /** @var array<string> Formats the watermarker can rewrite. */
    private const WATERMARKABLE = ['png', 'jpg', 'jpeg', 'webp'];

    #[\Override]
    protected function default_endpoint(): string {
        return defaults::IMAGE_ENDPOINT;
    }

    #[\Override]
    protected function default_model(): string {
        return defaults::IMAGE_MODEL;
    }

    #[\Override]
    protected function create_request_object(string $userid): RequestInterface {
        $capabilities = model_list::get_image_model_capabilities($this->get_model());

        $requestobj = new \stdClass();
        $requestobj->model = $this->get_model();
        $requestobj->prompt = $this->build_prompt();

        // Always one image. response_generate_image carries exactly one draft
        // file and core's own providers read only the first entry, so asking for
        // more would bill for images Moodle then discards. The most capable
        // Google image models cap n at 1 anyway.
        $this->set_if_allowed($requestobj, $capabilities, 'n', [1]);

        $this->set_if_allowed(
            $requestobj,
            $capabilities,
            'aspect_ratio',
            $this->aspect_ratio_preferences(),
        );

        $this->set_if_allowed(
            $requestobj,
            $capabilities,
            'quality',
            $this->quality_preferences(),
        );

        // Strict: an admin who asked for 2K should not silently get 4K, so an
        // unsupported tier is omitted and the model's own default applies.
        $this->set_if_allowed(
            $requestobj,
            $capabilities,
            'resolution',
            [(string) $this->get_action_setting('resolution', defaults::IMAGE_RESOLUTION)],
        );

        return new Request(
            method: 'POST',
            uri: '',
            body: json_encode($requestobj, JSON_UNESCAPED_SLASHES),
            headers: [
                'Content-Type' => 'application/json',
            ],
        );
    }

    #[\Override]
    protected function query_ai_api(): array {
        $response = parent::query_ai_api();

        // Placements cannot talk to the provider, so hand them a file.
        if (!empty($response['success'])) {
            $response['draftfile'] = $this->create_file_from_response(
                (int) $this->action->get_configuration('userid'),
                $response,
            );
        }

        return $response;
    }

    #[\Override]
    protected function handle_api_success(ResponseInterface $response): array {
        $bodyobj = json_decode((string) $response->getBody()->getContents());

        $first = $bodyobj->data[0] ?? null;
        $b64json = $first->b64_json ?? null;
        if (!is_string($b64json) || trim($b64json) === '') {
            return [
                'success' => false,
                'errorcode' => -1,
                'errormessage' => get_string('error:noimagereturned', 'aiprovider_n3xtopenrouter'),
            ];
        }

        return [
            'success' => true,
            'b64json' => $b64json,
            'output_format' => self::format_from_media_type($first->media_type ?? null),
            // Only some models rewrite the prompt, and the endpoint returns the
            // image inline rather than hosting it, so there is no source URL.
            'revisedprompt' => $first->revised_prompt ?? null,
            'sourceurl' => null,
            'model' => $this->get_model(),
        ];
    }

    /**
     * The prompt to send, including the style Moodle asked for.
     *
     * The endpoint has no style parameter - it is silently dropped if sent - so
     * the only way to honour the request is to say it in the prompt.
     *
     * @return string
     */
    protected function build_prompt(): string {
        $prompt = (string) $this->action->get_configuration('prompttext');
        $style = trim((string) ($this->action->get_configuration('style') ?? ''));

        if ($style !== '' && $style !== 'auto') {
            $prompt .= ' ' . get_string(
                'imagestylesuffix',
                'aiprovider_n3xtopenrouter',
                $style
            );
        }

        return $prompt;
    }

    /**
     * Aspect ratios to try for the ratio Moodle asked for, best first.
     *
     * @return array<string>
     */
    protected function aspect_ratio_preferences(): array {
        $ratio = (string) ($this->action->get_configuration('aspectratio') ?? '');

        return defaults::IMAGE_ASPECT_RATIOS[$ratio] ?? defaults::IMAGE_ASPECT_RATIOS['square'];
    }

    /**
     * Qualities to try for the quality Moodle asked for, best first.
     *
     * Core throws on an unrecognised quality. Failing an entire generation over
     * a label is worse than letting the model decide, so this falls back to the
     * endpoint's own 'auto'.
     *
     * @return array<string>
     */
    protected function quality_preferences(): array {
        $quality = (string) ($this->action->get_configuration('quality') ?? '');
        $mapped = defaults::IMAGE_QUALITIES[$quality] ?? null;

        return $mapped !== null
            ? [$mapped, defaults::IMAGE_QUALITY_FALLBACK]
            : [defaults::IMAGE_QUALITY_FALLBACK];
    }

    /**
     * Set a request parameter to the first preference the model accepts.
     *
     * Leaves the parameter off entirely when the model does not accept it, or
     * accepts none of the preferences.
     *
     * @param \stdClass $requestobj The request being built.
     * @param array $capabilities The model's published capabilities.
     * @param string $param The parameter name.
     * @param array $preferences Candidate values, best first.
     */
    protected function set_if_allowed(
        \stdClass $requestobj,
        array $capabilities,
        string $param,
        array $preferences,
    ): void {
        if (!array_key_exists($param, $capabilities)) {
            return;
        }

        $descriptor = $capabilities[$param];
        $allowed = $descriptor['values'] ?? null;
        $min = $descriptor['min'] ?? null;
        $max = $descriptor['max'] ?? null;

        foreach ($preferences as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_int($value)) {
                // A range, such as n.
                if (is_numeric($min)) {
                    $value = max((int) $min, $value);
                }
                if (is_numeric($max)) {
                    $value = min((int) $max, $value);
                }
                $requestobj->$param = $value;
                return;
            }

            // An enum, or a parameter whose allowed values are unknown.
            if (!is_array($allowed) || in_array($value, $allowed, true)) {
                $requestobj->$param = $value;
                return;
            }
        }
    }

    /**
     * Map a response media type onto a file extension.
     *
     * @param string|null $mediatype The media_type reported for the image.
     * @return string
     */
    protected static function format_from_media_type(?string $mediatype): string {
        return match (strtolower(trim((string) $mediatype))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            // The endpoint documents PNG as the usual case and omits media_type
            // only when the format could not be determined.
            default => 'png',
        };
    }

    /**
     * Decode the returned image, watermark it, and store it as a draft file.
     *
     * @param int $userid The user the draft file belongs to.
     * @param array $response The processed response, carrying b64json and output_format.
     * @return \stored_file
     */
    protected function create_file_from_response(int $userid, array $response): \stored_file {
        global $CFG;

        require_once("{$CFG->libdir}/filelib.php");

        $b64json = (string) $response['b64json'];
        $format = (string) $response['output_format'];
        $filename = substr(hash('sha512', $b64json), 0, 16) . '.' . $format;

        $tempdst = make_request_directory() . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($tempdst, base64_decode($b64json));

        $this->watermark($tempdst, $format);

        // The file goes to the user's draft area; placements move it from there.
        $fileinfo = new \stdClass();
        $fileinfo->contextid = \context_user::instance($userid)->id;
        $fileinfo->component = 'user';
        $fileinfo->filearea = 'draft';
        $fileinfo->itemid = file_get_unused_draft_itemid();
        $fileinfo->filepath = '/';
        $fileinfo->filename = $filename;

        $fs = get_file_storage();
        return $fs->create_file_from_string($fileinfo, file_get_contents($tempdst));
    }

    /**
     * Watermark a generated image in place, where the format allows it.
     *
     * Moodle watermarks AI-generated images so they are identifiable. The
     * watermarker is raster only, so vector output from models such as
     * recraft-v4-vector cannot carry one; returning the image unwatermarked
     * beats failing the generation, but it is worth a developer notice.
     *
     * @param string $path The image on disk.
     * @param string $format The file extension.
     */
    protected function watermark(string $path, string $format): void {
        if (!in_array($format, self::WATERMARKABLE, true)) {
            debugging(
                "Cannot watermark a {$format} image returned by {$this->get_model()}",
                DEBUG_DEVELOPER
            );
            return;
        }

        try {
            $image = new ai_image($path);
            $image->add_watermark()->save();
        } catch (\Throwable $e) {
            debugging('Could not watermark the generated image: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
