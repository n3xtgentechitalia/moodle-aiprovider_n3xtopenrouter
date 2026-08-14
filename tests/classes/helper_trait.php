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

namespace aiprovider_n3xtopenrouter\tests;

use aiprovider_n3xtopenrouter\provider;

/**
 * Shared scaffolding for this plugin's tests.
 *
 * Lives in tests/classes/ so that Moodle's PHPUnit autoloader resolves it from
 * the \aiprovider_n3xtopenrouter\tests\ namespace - see
 * \core_component::classloader(). A trait placed directly in tests/ is not
 * autoloadable and has to be require_once'd by every test that uses it.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait helper_trait {
    /**
     * Build a provider instance.
     *
     * @param array $actionconfig Action config, keyed by action class name as core stores it.
     * @param array $config Provider instance config.
     * @return provider
     */
    protected function build_provider(array $actionconfig = [], array $config = ['apikey' => 'test-key']): provider {
        return new provider(
            enabled: true,
            name: 'OpenRouter',
            config: json_encode($config),
            actionconfig: $actionconfig === [] ? '' : json_encode($actionconfig),
        );
    }

    /**
     * Build an action config block for a single action.
     *
     * @param string $actionclass The fully qualified action class name.
     * @param array $settings The action settings.
     * @return array
     */
    protected function build_actionconfig(string $actionclass, array $settings): array {
        return [
            $actionclass => [
                'enabled' => true,
                'settings' => $settings,
            ],
        ];
    }

    /**
     * Call a protected method on a processor.
     *
     * @param object $object The object to call into.
     * @param string $method The method name.
     * @param array $args Arguments to pass.
     * @return mixed
     */
    protected function call_protected(object $object, string $method, array $args = []): mixed {
        $reflection = new \ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $args);
    }

    /**
     * Seed the image model catalogue so tests need no network.
     *
     * @param array $catalogue [id => ['name' => string, 'params' => descriptor map]]
     */
    protected function seed_image_catalogue(array $catalogue): void {
        \core_cache\cache::make('aiprovider_n3xtopenrouter', 'models')
            ->set('imagemodels', $catalogue);
    }

    /**
     * Build an image generation response body.
     *
     * @param string $b64json The base64 image payload.
     * @param string|null $mediatype The media type, or null to omit it.
     * @param string|null $revisedprompt The revised prompt, or null to omit it.
     * @return string JSON encoded body.
     */
    protected function build_image_response_body(
        string $b64json = 'aGVsbG8=',
        ?string $mediatype = 'image/png',
        ?string $revisedprompt = null,
    ): string {
        $entry = ['b64_json' => $b64json];
        if ($mediatype !== null) {
            $entry['media_type'] = $mediatype;
        }
        if ($revisedprompt !== null) {
            $entry['revised_prompt'] = $revisedprompt;
        }

        return json_encode([
            'created' => 1748372400,
            'data' => [$entry],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 100, 'total_tokens' => 100],
        ]);
    }

    /**
     * Build a chat completion response body.
     *
     * @param string $content The assistant message content.
     * @param array $extra Extra top-level keys. A null value omits that key.
     * @return string JSON encoded body.
     */
    protected function build_response_body(string $content, array $extra = []): string {
        $body = [
            'id' => 'gen-test-1',
            'model' => 'google/gemini-3.7-flash',
            'system_fingerprint' => 'fp_test',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
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
}
