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
use core_ai\process_base;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Shared plumbing for the OpenRouter action processors.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends process_base {
    /**
     * Get the short action name, for example "generate_text".
     *
     * @return string
     */
    protected function get_action_name(): string {
        $action = get_class($this->action);
        return substr($action, (strrpos($action, '\\') + 1));
    }

    /**
     * Get the stored settings for the action being processed.
     *
     * Moodle keys a provider instance's action config by the *fully qualified
     * action class name* - see \core_ai\provider::initialise_action_settings()
     * and /ai/configure_actions.php, and compare every bundled provider, which
     * all read $this->provider->actionconfig[$this->action::class]['settings'].
     *
     * Earlier releases of this plugin looked the settings up by short action
     * name instead ("generate_text"), which never matched. The lookup silently
     * returned nothing, so every request fell back to the built-in defaults and
     * the model an admin chose in the UI was ignored. Keep the class name first.
     *
     * @return array
     */
    protected function get_action_settings(): array {
        $actionconfig = $this->provider->actionconfig ?? [];
        $actionclass = get_class($this->action);

        if (isset($actionconfig[$actionclass]['settings']) && is_array($actionconfig[$actionclass]['settings'])) {
            return $actionconfig[$actionclass]['settings'];
        }

        // Defensive fallbacks for hand-edited or partially migrated action config.
        $actionname = $this->get_action_name();
        if (isset($actionconfig[$actionname]['settings']) && is_array($actionconfig[$actionname]['settings'])) {
            return $actionconfig[$actionname]['settings'];
        }

        if (isset($actionconfig['settings']) && is_array($actionconfig['settings'])) {
            return $actionconfig['settings'];
        }

        return [];
    }

    /**
     * Read one action setting, falling back to site-level plugin config.
     *
     * The get_config() fallback only matters for sites carrying settings from
     * before per-instance provider configuration existed. It is deprecated and
     * scheduled for removal in 2.0.
     *
     * @param string $key The setting name.
     * @param mixed $default Returned when the setting is configured nowhere.
     * @return mixed
     */
    protected function get_action_setting(string $key, mixed $default = null): mixed {
        $settings = $this->get_action_settings();
        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        $actionname = $this->get_action_name();
        $legacy = get_config('aiprovider_n3xtopenrouter', "action_{$actionname}_{$key}");
        return $legacy !== false && $legacy !== null ? $legacy : $default;
    }

    /**
     * The endpoint used when the action has none configured.
     *
     * Overridden by actions that talk to a different OpenRouter endpoint.
     *
     * @return string
     */
    protected function default_endpoint(): string {
        return defaults::ENDPOINT;
    }

    /**
     * The model used when the action has none configured.
     *
     * @return string
     */
    protected function default_model(): string {
        return defaults::MODEL;
    }

    /**
     * Get the endpoint to send the request to.
     *
     * @return UriInterface
     */
    protected function get_endpoint(): UriInterface {
        $default = $this->default_endpoint();
        $endpoint = trim((string) $this->get_action_setting('endpoint', $default));
        return new Uri($endpoint !== '' ? $endpoint : $default);
    }

    /**
     * Get the model to send the request to.
     *
     * @return string
     */
    protected function get_model(): string {
        $default = $this->default_model();
        $model = trim((string) $this->get_action_setting('model', $default));
        return $model !== '' ? $model : $default;
    }

    /**
     * Get the sampling temperature, clamped to the range the API accepts.
     *
     * @return float
     */
    protected function get_temperature(): float {
        $temperature = (float) $this->get_action_setting('temperature', defaults::TEMPERATURE);
        return min(defaults::TEMPERATURE_MAX, max(defaults::TEMPERATURE_MIN, $temperature));
    }

    /**
     * Get the system instruction to send with the prompt.
     *
     * @return string
     */
    protected function get_system_instruction(): string {
        return (string) $this->get_action_setting(
            'systeminstruction',
            $this->action::get_system_instruction()
        );
    }

    /**
     * Build the request to send to OpenRouter.
     *
     * @param string $userid The hashed user id.
     * @return RequestInterface
     */
    abstract protected function create_request_object(string $userid): RequestInterface;

    /**
     * Turn a successful API response into an action response array.
     *
     * @param ResponseInterface $response The response object.
     * @return array
     */
    abstract protected function handle_api_success(ResponseInterface $response): array;

    #[\Override]
    protected function query_ai_api(): array {
        try {
            $request = $this->create_request_object(
                userid: $this->provider->generate_userid($this->action->get_configuration('userid')),
            );
            $request = $this->provider->add_authentication_headers($request);

            $client = \core\di::get(http_client::class);
            // Call the external AI service.
            $response = $client->send($request, [
                'base_uri' => $this->get_endpoint(),
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (RequestException $e) {
            // Handle any exceptions.
            return [
                'success' => false,
                'errorcode' => $e->getCode() ?: -1,
                'errormessage' => $e->getMessage() ?: get_string(
                    'error:requestfailed',
                    'aiprovider_n3xtopenrouter'
                ),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errorcode' => -1,
                'errormessage' => $e->getMessage() ?: get_string(
                    'error:requestfailed',
                    'aiprovider_n3xtopenrouter'
                ),
            ];
        }

        // Double-check the response codes, in case of a non 200 that didn't throw an error.
        $status = $response->getStatusCode();
        if ($status === 200) {
            try {
                return $this->handle_api_success($response);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'errorcode' => -1,
                    'errormessage' => $e->getMessage() ?: get_string(
                        'error:unexpectedresponse',
                        'aiprovider_n3xtopenrouter'
                    ),
                ];
            }
        }

        try {
            return $this->handle_api_error($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errorcode' => $status ?: -1,
                'errormessage' => $e->getMessage() ?: get_string(
                    'error:unexpectedresponse',
                    'aiprovider_n3xtopenrouter'
                ),
            ];
        }
    }

    /**
     * Turn an API error response into an action response array.
     *
     * @param ResponseInterface $response The response object.
     * @return array
     */
    protected function handle_api_error(ResponseInterface $response): array {
        $status = $response->getStatusCode();
        $reason = (string) $response->getReasonPhrase();
        $body = (string) $response->getBody()->getContents();
        $bodyarr = json_decode($body, true);

        $message = '';
        if (is_array($bodyarr)) {
            // OpenRouter mirrors OpenAI's nested error object, but upstream
            // providers sometimes surface a flat message instead.
            if (!empty($bodyarr['error']['message']) && is_string($bodyarr['error']['message'])) {
                $message = $bodyarr['error']['message'];
            } else if (!empty($bodyarr['message']) && is_string($bodyarr['message'])) {
                $message = $bodyarr['message'];
            } else if (!empty($bodyarr['error']) && is_string($bodyarr['error'])) {
                $message = $bodyarr['error'];
            }
        }

        if ($message === '') {
            // Fall back to the reason phrase, then the raw body.
            $message = $reason !== '' ? $reason : trim($body);
        }

        if ($message === '') {
            $message = get_string('error:httpstatus', 'aiprovider_n3xtopenrouter', $status);
        }

        return [
            'success' => false,
            'errorcode' => $status,
            'errormessage' => $message,
        ];
    }
}
