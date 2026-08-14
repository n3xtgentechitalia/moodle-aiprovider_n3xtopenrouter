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

use core_ai\aiactions;
use core_ai\form\action_settings_form;
use core_ai\rate_limiter;
use Psr\Http\Message\RequestInterface;

/**
 * The OpenRouter AI provider for Moodle, maintained by Next Gen Technologies Italia.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    /**
     * Get a provider instance setting, falling back to legacy plugin config.
     *
     * The get_config() fallback only matters for sites carrying settings from
     * before per-instance provider configuration existed. It is deprecated and
     * scheduled for removal in 2.0.
     *
     * @param string $key The setting name.
     * @param mixed $default Returned when the setting is configured nowhere.
     * @return mixed
     */
    private function get_provider_setting(string $key, mixed $default = null): mixed {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }
        $legacy = get_config('aiprovider_n3xtopenrouter', $key);
        return $legacy !== false && $legacy !== null ? $legacy : $default;
    }

    /**
     * Get the list of actions that this provider supports.
     *
     * @return array An array of action class names.
     */
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\generate_image::class,
        ];
    }

    /**
     * Generate a user id.
     *
     * This is a hash of the site id and user id, so OpenRouter can be told which
     * requests belong to the same person without being told who that person is.
     *
     * @param string $userid The user id.
     * @return string The generated user id.
     */
    public function generate_userid(string $userid): string {
        global $CFG;
        return hash('sha256', $CFG->siteidentifier . $userid);
    }

    /**
     * Add the headers OpenRouter expects on every request.
     *
     * @param \Psr\Http\Message\RequestInterface $request
     * @return \Psr\Http\Message\RequestInterface
     */
    public function add_authentication_headers(RequestInterface $request): RequestInterface {
        global $CFG, $SITE;

        $apikey = (string) $this->get_provider_setting('apikey', '');
        $request = $request->withAddedHeader('Authorization', "Bearer {$apikey}");

        // OpenRouter attributes traffic to a site through these two optional
        // headers. Neither carries personal data. Note there is deliberately no
        // OpenAI-Organization header: OpenRouter ignores it.
        if (!empty($CFG->wwwroot)) {
            $request = $request->withAddedHeader('HTTP-Referer', $CFG->wwwroot);
        }

        $sitename = trim(preg_replace('/[[:cntrl:]]+/', ' ', (string) ($SITE->fullname ?? '')));
        if ($sitename !== '') {
            $request = $request->withAddedHeader('X-Title', \core_text::substr($sitename, 0, 120));
        }

        return $request;
    }

    /**
     * Check if the request is allowed by the rate limiter.
     *
     * @param aiactions\base $action The action to check.
     * @return array|bool True on success, array of error details on failure.
     */
    public function is_request_allowed(aiactions\base $action): array|bool {
        $ratelimiter = \core\di::get(rate_limiter::class);
        $component = \core\component::get_component_from_classname(get_class($this));

        // Check the user rate limit.
        $enableuserratelimit = (bool) $this->get_provider_setting('enableuserratelimit', false);
        $userratelimit = (int) $this->get_provider_setting('userratelimit', 0);
        if ($enableuserratelimit) {
            if (
                !$ratelimiter->check_user_rate_limit(
                    component: $component,
                    ratelimit: $userratelimit,
                    userid: $action->get_configuration('userid')
                )
            ) {
                return [
                    'success' => false,
                    'errorcode' => 429,
                    'errormessage' => 'User rate limit exceeded',
                ];
            }
        }

        // Check the global rate limit.
        $enableglobalratelimit = (bool) $this->get_provider_setting('enableglobalratelimit', false);
        $globalratelimit = (int) $this->get_provider_setting('globalratelimit', 0);
        if ($enableglobalratelimit) {
            if (
                !$ratelimiter->check_global_rate_limit(
                    component: $component,
                    ratelimit: $globalratelimit
                )
            ) {
                return [
                    'success' => false,
                    'errorcode' => 429,
                    'errormessage' => 'Global rate limit exceeded',
                ];
            }
        }

        return true;
    }

    /**
     * Get the settings form for a specific action.
     *
     * @param string $action The action class name.
     * @param array $customdata Custom data passed by core.
     * @return action_settings_form|bool A form instance, or false when unsupported.
     */
    public static function get_action_settings(string $action, array $customdata = []): action_settings_form|bool {
        $actionname = substr($action, (strrpos($action, '\\') + 1));
        $customdata['actionname'] = $actionname;
        $customdata['action'] = $action;
        $customdata['providername'] = $customdata['providername'] ?? 'aiprovider_n3xtopenrouter';

        return match ($actionname) {
            'generate_text' => new form\action_generate_text_form(null, $customdata),
            'summarise_text' => new form\action_summarise_text_form(null, $customdata),
            'generate_image' => new form\action_generate_image_form(null, $customdata),
            default => false,
        };
    }

    /**
     * Get the settings a newly created provider instance starts with.
     *
     * Without this, core stores an empty settings array for each action, so a
     * fresh instance shows no model at all and every request silently falls back
     * to the built-in defaults. Seeding the form defaults means the model in use
     * is visible in the UI from the moment the instance is created.
     *
     * @param string $action The action class name.
     * @return array The default settings for the action.
     */
    public static function get_action_setting_defaults(string $action): array {
        $mform = static::get_action_settings($action);
        if ($mform === false) {
            return [];
        }

        return $mform->get_defaults();
    }

    /**
     * Check this provider has the minimal configuration to work.
     *
     * @return bool Return true if configured.
     */
    public function is_provider_configured(): bool {
        return !empty($this->get_provider_setting('apikey', ''));
    }
}
