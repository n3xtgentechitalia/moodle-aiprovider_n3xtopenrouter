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

use core\http_client;
use GuzzleHttp\RequestOptions;

/**
 * The catalogues of models an admin can pick from, read from OpenRouter.
 *
 * OpenRouter proxies hundreds of models and the set changes constantly, so a
 * hard-coded list would be wrong within weeks. Each catalogue is fetched once a
 * day and cached; if it cannot be reached the forms still render from the
 * fallbacks in defaults, and a free-text field always allows any model id.
 *
 * Text and images are two separate catalogues on two separate endpoints. The
 * image one also reports, per model, which request parameters that model
 * accepts - which matters because sending an unsupported parameter is an error,
 * and support varies widely (aspect_ratio is near universal, quality is not).
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_list {
    /** @var string Cache key holding the text-capable model list. */
    private const CACHE_KEY_TEXT = 'textmodels';

    /** @var string Cache key holding the image model catalogue with capabilities. */
    private const CACHE_KEY_IMAGE = 'imagemodels';

    /**
     * Models usable for the text actions, as a form-ready [id => label] map.
     *
     * @return array<string, string>
     */
    public static function get_text_models(): array {
        $cache = self::get_cache();

        $cached = $cache?->get(self::CACHE_KEY_TEXT);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $models = self::fetch_text_models();
        if (empty($models)) {
            // Deliberately not cached: a transient outage should not pin the
            // fallback list in place for a whole day.
            return self::add_labels(defaults::MODELS_FALLBACK);
        }

        $cache?->set(self::CACHE_KEY_TEXT, $models);
        return $models;
    }

    /**
     * Whether the text list currently offered came from OpenRouter.
     *
     * @return bool
     */
    public static function is_live(): bool {
        return is_array(self::get_cache()?->get(self::CACHE_KEY_TEXT));
    }

    /**
     * Models usable for image generation, as a form-ready [id => label] map.
     *
     * @return array<string, string>
     */
    public static function get_image_models(): array {
        $catalogue = self::get_image_catalogue();
        $names = [];
        foreach ($catalogue as $id => $entry) {
            $names[$id] = $entry['name'] ?? $id;
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return self::add_labels($names);
    }

    /**
     * What a given image model accepts, as a map of parameter name to descriptor.
     *
     * A descriptor is the typed constraint OpenRouter publishes, for example
     * ['type' => 'enum', 'values' => ['1:1', '16:9']] or
     * ['type' => 'range', 'min' => 1, 'max' => 4]. An empty descriptor means the
     * parameter is accepted but its allowed values are unknown.
     *
     * Falls back to defaults::IMAGE_CAPABILITIES_ASSUMED for a model absent from
     * the catalogue, which covers hand-typed ids and catalogue outages.
     *
     * @param string $model The model id.
     * @return array<string, array>
     */
    public static function get_image_model_capabilities(string $model): array {
        $catalogue = self::get_image_catalogue();
        $caps = $catalogue[$model]['params'] ?? null;

        return is_array($caps) && !empty($caps) ? $caps : defaults::IMAGE_CAPABILITIES_ASSUMED;
    }

    /**
     * Whether the image catalogue currently offered came from OpenRouter.
     *
     * @return bool
     */
    public static function image_list_is_live(): bool {
        return is_array(self::get_cache()?->get(self::CACHE_KEY_IMAGE));
    }

    /**
     * Drop the cached catalogues so the next read fetches them again.
     */
    public static function purge_cache(): void {
        $cache = self::get_cache();
        $cache?->delete(self::CACHE_KEY_TEXT);
        $cache?->delete(self::CACHE_KEY_IMAGE);
    }

    /**
     * The image catalogue, keyed by model id.
     *
     * @return array<string, array{name: string, params: array<string>}>
     */
    private static function get_image_catalogue(): array {
        $cache = self::get_cache();

        $cached = $cache?->get(self::CACHE_KEY_IMAGE);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $catalogue = self::fetch_image_catalogue();
        if (empty($catalogue)) {
            return defaults::IMAGE_MODELS_FALLBACK;
        }

        $cache?->set(self::CACHE_KEY_IMAGE, $catalogue);
        return $catalogue;
    }

    /**
     * Read OpenRouter's text model catalogue.
     *
     * @return array<string, string> Form-ready [id => label] map, empty on failure.
     */
    private static function fetch_text_models(): array {
        $payload = self::request(defaults::MODELS_ENDPOINT);
        if (!is_array($payload['data'] ?? null)) {
            return [];
        }

        $models = [];
        foreach ($payload['data'] as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = (string) ($model['id'] ?? '');
            if ($id === '' || !self::is_usable_text_model($id, $model)) {
                continue;
            }
            $models[$id] = (string) ($model['name'] ?? $id);
        }

        if (empty($models)) {
            return [];
        }

        asort($models, SORT_NATURAL | SORT_FLAG_CASE);

        // Keep the auto router at the top: it is the one entry that is a routing
        // policy rather than a model, and admins look for it first.
        if (isset($models[defaults::MODEL_AUTO])) {
            $auto = $models[defaults::MODEL_AUTO];
            unset($models[defaults::MODEL_AUTO]);
            $models = [defaults::MODEL_AUTO => $auto] + $models;
        }

        return self::add_labels($models);
    }

    /**
     * Read OpenRouter's image model catalogue, including per-model capabilities.
     *
     * @return array<string, array{name: string, params: array<string>}> Empty on failure.
     */
    private static function fetch_image_catalogue(): array {
        $payload = self::request(defaults::IMAGE_MODELS_ENDPOINT);
        $models = $payload['data'] ?? $payload;
        if (!is_array($models)) {
            return [];
        }

        $catalogue = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = (string) ($model['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $catalogue[$id] = [
                'name' => (string) ($model['name'] ?? $id),
                'params' => self::normalise_capabilities($model['supported_parameters'] ?? null),
            ];
        }

        return $catalogue;
    }

    /**
     * Normalise a supported_parameters block into a name => descriptor map.
     *
     * OpenRouter publishes an object keyed by parameter name, whose values are
     * typed constraint descriptors. The fallback catalogue in defaults instead
     * lists bare parameter names, since spelling out every enum by hand would
     * rot immediately. Both shapes are accepted here, with a bare name becoming
     * an empty descriptor - accepted, allowed values unknown.
     *
     * @param mixed $params The raw supported_parameters value.
     * @return array<string, array>
     */
    private static function normalise_capabilities(mixed $params): array {
        if (!is_array($params)) {
            return [];
        }

        $caps = [];
        foreach ($params as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // A bare list of parameter names.
                $caps[$value] = [];
            } else if (is_string($key)) {
                $caps[$key] = is_array($value) ? $value : [];
            }
        }

        return $caps;
    }

    /**
     * GET a JSON payload from OpenRouter.
     *
     * @param string $url The endpoint to read.
     * @return array The decoded payload, empty on any failure.
     */
    private static function request(string $url): array {
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request('GET', $url, [
                RequestOptions::TIMEOUT => defaults::MODELS_TIMEOUT,
                RequestOptions::HTTP_ERRORS => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $payload = json_decode((string) $response->getBody()->getContents(), true);
        } catch (\Throwable $e) {
            // An admin form has to render even when OpenRouter is unreachable.
            debugging(
                "Could not read the OpenRouter catalogue at {$url}: " . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * Whether a text catalogue entry can serve a synchronous chat completion.
     *
     * @param string $id The model id.
     * @param array $model The catalogue entry.
     * @return bool
     */
    private static function is_usable_text_model(string $id, array $model): bool {
        // Batch variants are queued asynchronously and never answer a chat
        // completion call, so offering them would only produce runtime errors.
        if (str_ends_with($id, ':batch')) {
            return false;
        }

        $outputs = $model['architecture']['output_modalities'] ?? null;
        if (!is_array($outputs)) {
            // Older catalogue entries omit the modality block; assume text.
            return true;
        }

        return in_array('text', $outputs, true);
    }

    /**
     * Append the model id to each label.
     *
     * Display names collide across providers and revisions, and the id is what
     * actually gets billed, so the admin needs to see both.
     *
     * @param array<string, string> $models
     * @return array<string, string>
     */
    private static function add_labels(array $models): array {
        $labelled = [];
        foreach ($models as $id => $name) {
            $labelled[$id] = $name === $id ? $id : "{$name} ({$id})";
        }
        return $labelled;
    }

    /**
     * The model cache, or null when it is unavailable.
     *
     * The cache definition arrives with this plugin, so it can legitimately be
     * missing mid-upgrade. An admin form should degrade to an uncached fetch in
     * that window rather than fail outright.
     *
     * @return \core_cache\cache|null
     */
    private static function get_cache(): ?\core_cache\cache {
        try {
            return \core_cache\cache::make('aiprovider_n3xtopenrouter', 'models');
        } catch (\Throwable $e) {
            debugging('OpenRouter model cache unavailable: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}
