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

use aiprovider_n3xtopenrouter\compat;
use aiprovider_n3xtopenrouter\defaults;
use aiprovider_n3xtopenrouter\model_list;
use core_ai\form\action_settings_form;

/**
 * Shared settings form for the OpenRouter text actions.
 *
 * Every setting an admin can change for an action lives here: which model to
 * call, where to call it, and how it should behave. The provider instance form
 * only holds the API key, so this is the form to look at when wondering "which
 * model is this site actually using?".
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_form extends action_settings_form {
    /** @var array The stored settings for this action. */
    protected array $actionconfig = [];

    /** @var string|null Where to send the admin after saving. */
    protected ?string $returnurl = null;

    /** @var string Short action name, for example "generate_text". */
    protected string $actionname = 'generate_text';

    /** @var string Fully qualified action class name. */
    protected string $action = '';

    /** @var int The provider instance being configured. */
    protected int $providerid = 0;

    /** @var string The provider plugin name. */
    protected string $providername = 'aiprovider_n3xtopenrouter';

    /**
     * Whether this action caps the length of what the model returns.
     *
     * @return bool
     */
    protected function has_word_cap(): bool {
        return false;
    }

    #[\Override]
    protected function definition(): void {
        compat::ensure_moodle_pear_loaded();

        $rawactionconfig = $this->_customdata['actionconfig'] ?? [];
        // Core passes the whole action config block; tolerate being handed just
        // the settings array, which is what get_action_setting_defaults() sees.
        $this->actionconfig = $rawactionconfig['settings'] ?? $rawactionconfig;

        $returnurl = $this->_customdata['returnurl'] ?? null;
        $this->returnurl = $returnurl !== null ? (string) $returnurl : null;
        $this->actionname = $this->_customdata['actionname'] ?? 'generate_text';
        $this->action = (string) ($this->_customdata['action'] ?? '');
        $this->providerid = (int) ($this->_customdata['providerid'] ?? 0);
        $this->providername = $this->_customdata['providername'] ?? 'aiprovider_n3xtopenrouter';

        $this->_form->addElement('header', 'generalsettingsheader', get_string('general', 'core'));

        $this->add_model_fields();
        $this->add_action_fields();
        $this->add_hidden_fields();

        $this->set_data($this->actionconfig);
    }

    /**
     * Add the fields specific to this action, after the model chooser.
     *
     * @return void
     */
    protected function add_action_fields(): void {
        $this->add_endpoint_field();
        $this->add_temperature_field();
        if ($this->has_word_cap()) {
            $this->add_maxwords_field();
        }
        $this->add_systeminstruction_field();
    }

    /**
     * The models offered by the chooser.
     *
     * @return array<string, string>
     */
    protected function get_model_options(): array {
        return model_list::get_text_models();
    }

    /**
     * Whether the offered model list came from OpenRouter rather than a fallback.
     *
     * @return bool
     */
    protected function model_list_is_live(): bool {
        return model_list::is_live();
    }

    /**
     * The model selected when the action has none configured.
     *
     * @return string
     */
    protected function default_model(): string {
        return defaults::MODEL;
    }

    /**
     * The endpoint offered when the action has none configured.
     *
     * @return string
     */
    protected function default_endpoint(): string {
        return defaults::ENDPOINT;
    }

    /**
     * Add the model chooser and its free-text companion.
     */
    protected function add_model_fields(): void {
        $mform = $this->_form;
        $models = $this->get_model_options();

        $configured = trim((string) ($this->actionconfig['model'] ?? ''));
        if ($configured === '') {
            $selected = $this->default_model();
        } else if (array_key_exists($configured, $models)) {
            $selected = $configured;
        } else {
            // Either hand-typed, or a model OpenRouter has since retired.
            $selected = 'custom';
        }

        $options = $models;
        if ($selected !== 'custom' && !array_key_exists($selected, $options)) {
            // Never render a chooser whose selected value is not one of its options.
            $options = [$selected => $selected] + $options;
        }
        $options['custom'] = get_string('custommodel', 'aiprovider_n3xtopenrouter');

        $mform->addElement(
            'select',
            'modeltemplate',
            get_string("action:{$this->actionname}:model", 'aiprovider_n3xtopenrouter'),
            $options,
        );
        $mform->setType('modeltemplate', PARAM_TEXT);
        $mform->addRule('modeltemplate', null, 'required', null, 'client');
        $mform->setDefault('modeltemplate', $selected);
        $mform->addHelpButton(
            'modeltemplate',
            "action:{$this->actionname}:model",
            'aiprovider_n3xtopenrouter'
        );

        // \core_ai\form\action_settings_form::get_data() copies modeltemplate
        // (or custommodel) into this field, and that is what gets stored.
        $mform->addElement('hidden', 'model', $selected);
        $mform->setType('model', PARAM_TEXT);

        $mform->addElement(
            'text',
            'custommodel',
            get_string('custommodel', 'aiprovider_n3xtopenrouter'),
            ['size' => 50],
        );
        $mform->setType('custommodel', PARAM_TEXT);
        $mform->setDefault('custommodel', $configured);
        $mform->hideIf('custommodel', 'modeltemplate', 'neq', 'custom');
        $mform->addHelpButton('custommodel', 'custommodel', 'aiprovider_n3xtopenrouter');

        if (!$this->model_list_is_live()) {
            // Say so rather than presenting a short built-in list as the catalogue.
            $mform->addElement(
                'static',
                'modellistnotice',
                '',
                get_string('modellistunavailable', 'aiprovider_n3xtopenrouter'),
            );
        }
    }

    /**
     * Add the endpoint field.
     */
    protected function add_endpoint_field(): void {
        $mform = $this->_form;
        $mform->addElement(
            'text',
            'endpoint',
            get_string("action:{$this->actionname}:endpoint", 'aiprovider_n3xtopenrouter'),
            ['size' => 60],
        );
        $mform->setType('endpoint', PARAM_URL);
        $mform->addRule('endpoint', null, 'required', null, 'client');
        $mform->setDefault('endpoint', $this->actionconfig['endpoint'] ?? $this->default_endpoint());
        $mform->addHelpButton(
            'endpoint',
            "action:{$this->actionname}:endpoint",
            'aiprovider_n3xtopenrouter'
        );
    }

    /**
     * Add the temperature field.
     */
    protected function add_temperature_field(): void {
        $mform = $this->_form;
        $mform->addElement(
            'text',
            'temperature',
            get_string("action:{$this->actionname}:temperature", 'aiprovider_n3xtopenrouter'),
            ['size' => 6],
        );
        // Kept raw rather than PARAM_FLOAT so that validation() can reject a bad
        // value instead of silently cleaning it to zero.
        $mform->setType('temperature', PARAM_RAW_TRIMMED);
        $mform->setDefault('temperature', $this->actionconfig['temperature'] ?? defaults::TEMPERATURE);
        $mform->addHelpButton(
            'temperature',
            "action:{$this->actionname}:temperature",
            'aiprovider_n3xtopenrouter'
        );
    }

    /**
     * Add the word cap field.
     */
    protected function add_maxwords_field(): void {
        $mform = $this->_form;
        $mform->addElement(
            'text',
            'maxwords',
            get_string("action:{$this->actionname}:maxwords", 'aiprovider_n3xtopenrouter'),
            ['size' => 6],
        );
        $mform->setType('maxwords', PARAM_RAW_TRIMMED);
        $mform->setDefault('maxwords', $this->actionconfig['maxwords'] ?? defaults::MAXWORDS);
        $mform->addHelpButton(
            'maxwords',
            "action:{$this->actionname}:maxwords",
            'aiprovider_n3xtopenrouter'
        );
    }

    /**
     * Add the system instruction field.
     */
    protected function add_systeminstruction_field(): void {
        $mform = $this->_form;
        $mform->addElement(
            'textarea',
            'systeminstruction',
            get_string("action:{$this->actionname}:systeminstruction", 'aiprovider_n3xtopenrouter'),
            ['rows' => 8, 'cols' => 80],
        );
        $mform->setType('systeminstruction', PARAM_TEXT);
        $default = $this->action !== '' ? $this->action::get_system_instruction() : '';
        $mform->setDefault('systeminstruction', $this->actionconfig['systeminstruction'] ?? $default);
        $mform->addHelpButton(
            'systeminstruction',
            "action:{$this->actionname}:systeminstruction",
            'aiprovider_n3xtopenrouter'
        );
    }

    /**
     * Add the fields /ai/configure_actions.php needs back on submit.
     *
     * Without these the save fails with "A required parameter (provider) was missing".
     */
    protected function add_hidden_fields(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'provider', $this->providername);
        $mform->setType('provider', PARAM_PLUGIN);

        $mform->addElement('hidden', 'providerid', $this->providerid);
        $mform->setType('providerid', PARAM_INT);

        $mform->addElement('hidden', 'action', $this->action);
        $mform->setType('action', PARAM_TEXT);

        if ($this->returnurl !== null && $this->returnurl !== '') {
            $mform->addElement('hidden', 'returnurl', $this->returnurl);
            $mform->setType('returnurl', PARAM_LOCALURL);
        }
    }

    #[\Override]
    public function get_data(): ?\stdClass {
        // The parent resolves modeltemplate/custommodel into model for us.
        $data = parent::get_data();

        if ($data !== null) {
            // Chooser scaffolding, not settings: keep it out of the stored config.
            unset($data->modeltemplate, $data->custommodel, $data->modellistnotice, $data->capabilitynotice);
        }

        return $data;
    }

    #[\Override]
    public function get_defaults(): array {
        $data = parent::get_defaults();
        // Chooser scaffolding and form furniture, none of which is a setting.
        unset(
            $data['modeltemplate'],
            $data['custommodel'],
            $data['modellistnotice'],
            $data['capabilitynotice'],
            $data['submitbutton'],
            $data['cancel'],
        );
        return $data;
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (($data['modeltemplate'] ?? '') === 'custom' && trim((string) ($data['custommodel'] ?? '')) === '') {
            $errors['custommodel'] = get_string('required');
        }

        // Not every action has a temperature: the image action does not.
        if (array_key_exists('temperature', $data)) {
            $temperature = (string) $data['temperature'];
            if ($temperature === '' || !is_numeric($temperature)
                    || (float) $temperature < defaults::TEMPERATURE_MIN
                    || (float) $temperature > defaults::TEMPERATURE_MAX) {
                $errors['temperature'] = get_string(
                    'error:temperaturerange',
                    'aiprovider_n3xtopenrouter',
                    (object) [
                        'min' => defaults::TEMPERATURE_MIN,
                        'max' => defaults::TEMPERATURE_MAX,
                    ],
                );
            }
        }

        if (array_key_exists('maxwords', $data) && !ctype_digit((string) $data['maxwords'])) {
            $errors['maxwords'] = get_string('error:maxwords', 'aiprovider_n3xtopenrouter');
        }

        return $errors;
    }
}
