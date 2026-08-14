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

/**
 * Default values shared by the action settings forms and the request processors.
 *
 * These live in one place on purpose: previously each processor and each form
 * repeated its own literal default, so the default offered by the UI could drift
 * from the one a request actually fell back to.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class defaults {
    /** @var string Model used when an action has no model configured. */
    public const MODEL = 'google/gemini-3.7-flash';

    /** @var string OpenRouter's automatic router, which picks a model per request. */
    public const MODEL_AUTO = 'openrouter/auto';

    /** @var string OpenAI-compatible chat completions endpoint. */
    public const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /** @var string Sampling temperature, as stored by the form. */
    public const TEMPERATURE = '0.2';

    /** @var float Lowest temperature the OpenRouter API accepts. */
    public const TEMPERATURE_MIN = 0.0;

    /** @var float Highest temperature the OpenRouter API accepts. */
    public const TEMPERATURE_MAX = 2.0;

    /** @var int Words a generated summary is capped at. Zero disables the cap. */
    public const MAXWORDS = 500;

    /** @var string Endpoint listing every model available on OpenRouter. Needs no API key. */
    public const MODELS_ENDPOINT = 'https://openrouter.ai/api/v1/models';

    /** @var int Seconds the fetched model list stays cached. One day. */
    public const MODELS_CACHE_TTL = 86400;

    /** @var int Seconds to wait for the model list before falling back to MODELS_FALLBACK. */
    public const MODELS_TIMEOUT = 5;

    /** @var string Model used when the image action has no model configured. */
    public const IMAGE_MODEL = 'google/gemini-3.1-flash-image';

    /** @var string OpenRouter's unified image generation endpoint. */
    public const IMAGE_ENDPOINT = 'https://openrouter.ai/api/v1/images';

    /** @var string Endpoint listing the image models, with their capabilities. Needs no API key. */
    public const IMAGE_MODELS_ENDPOINT = 'https://openrouter.ai/api/v1/images/models';

    /** @var string Resolution tier requested when the model supports one. */
    public const IMAGE_RESOLUTION = '1K';

    /** @var array Resolution tiers the endpoint accepts. */
    public const IMAGE_RESOLUTIONS = ['512', '1K', '2K', '4K'];

    /**
     * Moodle's aspect ratios mapped onto the ratios the endpoint accepts, best first.
     *
     * The first choice for each matches the pixel ratio core's own providers use
     * for that name (1024x1024, 1536x1024, 1024x1536). The rest are fallbacks,
     * because the allowed set differs per model: 3:2 is accepted by 29 of the 43
     * image models, while 16:9 and 4:3 are accepted by 38. Without a fallback,
     * asking recraft/recraft-v4 for a landscape image would simply fail.
     *
     * @var array<string, array<string>>
     */
    public const IMAGE_ASPECT_RATIOS = [
        'square' => ['1:1', 'auto'],
        'landscape' => ['3:2', '16:9', '4:3', 'auto'],
        'portrait' => ['2:3', '9:16', '3:4', 'auto'],
    ];

    /**
     * Moodle's quality names mapped onto the endpoint's enum.
     *
     * @var array<string, string>
     */
    public const IMAGE_QUALITIES = [
        'standard' => 'medium',
        'hd' => 'high',
    ];

    /** @var string Quality sent when Moodle asks for something not in IMAGE_QUALITIES. */
    public const IMAGE_QUALITY_FALLBACK = 'auto';

    /**
     * Capabilities assumed for a model absent from the capability list.
     *
     * Covers hand-typed model ids and the window in which the catalogue is
     * unreachable. Deliberately conservative: sending a parameter a model does
     * not accept is answered with an error, and support varies a lot -
     * aspect_ratio and n are accepted by 40 of the 43 image models, whereas
     * quality is accepted by only 7, so quality is not assumed.
     *
     * An empty descriptor means "accepted, allowed values unknown".
     *
     * @var array<string, array>
     */
    public const IMAGE_CAPABILITIES_ASSUMED = [
        'aspect_ratio' => [],
        'n' => ['type' => 'range', 'min' => 1, 'max' => 1],
    ];

    /**
     * Models offered when OpenRouter's model list cannot be reached.
     *
     * This is a safety net for the settings form, not a curated recommendation:
     * the live catalogue is what admins normally see. Every entry was verified
     * against OpenRouter when this release was cut, and the form always offers a
     * free-text "custom model" field too, so a model missing here is never
     * unreachable.
     *
     * @var array<string, string>
     */
    public const MODELS_FALLBACK = [
        self::MODEL_AUTO => 'Auto Router',
        'google/gemini-3.7-flash' => 'Google: Gemini 3.7 Flash',
        'google/gemini-3.6-flash' => 'Google: Gemini 3.6 Flash',
        'openai/gpt-5.6-luna' => 'OpenAI: GPT-5.6 Luna',
        'anthropic/claude-sonnet-5' => 'Anthropic: Claude Sonnet 5',
        'anthropic/claude-opus-5' => 'Anthropic: Claude Opus 5',
        'deepseek/deepseek-v3.2' => 'DeepSeek: DeepSeek V3.2',
        'meta-llama/llama-3.3-70b-instruct' => 'Meta: Llama 3.3 70B Instruct',
        'mistralai/mistral-small-2603' => 'Mistral: Mistral Small 4',
        'qwen/qwen3.7-flash' => 'Qwen: Qwen3.7 Flash',
    ];

    /**
     * Image models offered when the capability list cannot be reached.
     *
     * Each entry carries the parameters that model actually accepts, so a
     * request is still shaped correctly while the live list is unavailable.
     * Verified against the OpenRouter image catalogue when this release was cut.
     *
     * @var array<string, array{name: string, params: array<string>}>
     */
    public const IMAGE_MODELS_FALLBACK = [
        'google/gemini-3.1-flash-image' => [
            'name' => 'Google: Nano Banana 2 (Gemini 3.1 Flash Image)',
            'params' => ['resolution', 'aspect_ratio', 'n'],
        ],
        'google/gemini-3-pro-image' => [
            'name' => 'Google: Nano Banana Pro (Gemini 3 Pro Image)',
            'params' => ['resolution', 'aspect_ratio', 'n'],
        ],
        'google/gemini-2.5-flash-image' => [
            'name' => 'Google: Nano Banana (Gemini 2.5 Flash Image)',
            'params' => ['aspect_ratio', 'n'],
        ],
        'openai/gpt-image-2' => [
            'name' => 'OpenAI: GPT Image 2',
            'params' => ['aspect_ratio', 'quality', 'background', 'n'],
        ],
        'openai/gpt-image-1-mini' => [
            'name' => 'OpenAI: GPT Image 1 Mini',
            'params' => ['aspect_ratio', 'quality', 'background', 'n'],
        ],
        'black-forest-labs/flux.2-pro' => [
            'name' => 'Black Forest Labs: FLUX.2 Pro',
            'params' => ['aspect_ratio', 'output_format', 'n', 'seed'],
        ],
        'qwen/qwen-image-3' => [
            'name' => 'Qwen: Qwen Image 3',
            'params' => ['resolution', 'aspect_ratio', 'n', 'seed'],
        ],
        'bytedance-seed/seedream-5-0-lite' => [
            'name' => 'ByteDance Seed: Seedream 5.0 Lite',
            'params' => ['resolution', 'aspect_ratio', 'n', 'seed'],
        ],
        'recraft/recraft-v4' => [
            'name' => 'Recraft: Recraft V4',
            'params' => ['aspect_ratio', 'n'],
        ],
        'x-ai/grok-imagine-image-2.0' => [
            'name' => 'xAI: Grok Imagine Image 2.0',
            'params' => ['resolution', 'aspect_ratio', 'quality', 'n'],
        ],
    ];
}
