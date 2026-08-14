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

use core_ai\hook\after_ai_provider_form_hook;

/**
 * Hook listeners for the OpenRouter AI provider plugin.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Add the provider instance fields to the AI provider form.
     *
     * Only credentials belong here. Everything about *how* a request is made -
     * model, endpoint, temperature, system instruction - is configured per
     * action, under Actions on the provider instance page.
     *
     * @param after_ai_provider_form_hook $hook
     */
    public static function set_form_definition_for_aiprovider_n3xtopenrouter(after_ai_provider_form_hook $hook): void {
        if ($hook->plugin !== 'aiprovider_n3xtopenrouter') {
            return;
        }

        compat::ensure_moodle_pear_loaded();

        $mform = $hook->mform;

        $mform->addElement(
            'passwordunmask',
            'apikey',
            get_string('apikey', 'aiprovider_n3xtopenrouter'),
            ['size' => 75],
        );
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addRule('apikey', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('apikey', 'apikey', 'aiprovider_n3xtopenrouter');

        // Point admins at the place the model is actually chosen. The single most
        // common confusion with this plugin is looking for it on this page.
        $mform->addElement(
            'static',
            'actionsettingsnotice',
            '',
            get_string('actionsettingsnotice', 'aiprovider_n3xtopenrouter'),
        );
    }
}
