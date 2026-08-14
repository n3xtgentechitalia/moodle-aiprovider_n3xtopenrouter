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

namespace aiprovider_n3xtopenrouter\form;

use aiprovider_n3xtopenrouter\defaults;
use aiprovider_n3xtopenrouter\model_list;

/**
 * Settings form for the generate_image action.
 *
 * Image generation talks to a different OpenRouter endpoint with a different
 * parameter set, so it offers its own model catalogue and has no temperature or
 * system instruction.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_generate_image_form extends action_form {
    #[\Override]
    protected function get_model_options(): array {
        return model_list::get_image_models();
    }

    #[\Override]
    protected function model_list_is_live(): bool {
        return model_list::image_list_is_live();
    }

    #[\Override]
    protected function default_model(): string {
        return defaults::IMAGE_MODEL;
    }

    #[\Override]
    protected function default_endpoint(): string {
        return defaults::IMAGE_ENDPOINT;
    }

    #[\Override]
    protected function add_action_fields(): void {
        $this->add_endpoint_field();
        $this->add_resolution_field();
        $this->add_capability_notice();
    }

    /**
     * Add the resolution tier field.
     */
    protected function add_resolution_field(): void {
        $mform = $this->_form;

        $options = array_combine(defaults::IMAGE_RESOLUTIONS, defaults::IMAGE_RESOLUTIONS);
        $mform->addElement(
            'select',
            'resolution',
            get_string('action:generate_image:resolution', 'aiprovider_n3xtopenrouter'),
            $options,
        );
        $mform->setType('resolution', PARAM_TEXT);
        $mform->setDefault('resolution', $this->actionconfig['resolution'] ?? defaults::IMAGE_RESOLUTION);
        $mform->addHelpButton(
            'resolution',
            'action:generate_image:resolution',
            'aiprovider_n3xtopenrouter'
        );
    }

    /**
     * State which parameters the selected model actually accepts.
     *
     * Support varies sharply between image models - quality is accepted by 7 of
     * the 43, resolution by 19 - and a request only carries what the chosen model
     * accepts. Saying so here stops a setting looking broken when it is simply
     * not applicable. Reflects the saved model, so it updates on save rather
     * than as the chooser changes.
     */
    protected function add_capability_notice(): void {
        $model = trim((string) ($this->actionconfig['model'] ?? '')) ?: $this->default_model();
        $capabilities = model_list::get_image_model_capabilities($model);

        $accepted = array_keys($capabilities);
        sort($accepted);

        $this->_form->addElement(
            'static',
            'capabilitynotice',
            get_string('imagecapabilities', 'aiprovider_n3xtopenrouter'),
            get_string(
                'imagecapabilities_detail',
                'aiprovider_n3xtopenrouter',
                (object) [
                    'model' => s($model),
                    'params' => s(implode(', ', $accepted)),
                ],
            ),
        );
    }
}
