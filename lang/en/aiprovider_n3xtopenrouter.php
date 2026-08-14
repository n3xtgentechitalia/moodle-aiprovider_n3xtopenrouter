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
 * Strings for component aiprovider_n3xtopenrouter, language 'en'.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['action:generate_image:endpoint'] = 'API endpoint';
$string['action:generate_image:endpoint_help'] = 'OpenRouter\'s unified image endpoint. This is not the chat endpoint the text actions use, and it takes a different set of parameters. Leave it alone unless you are routing requests through a proxy.';
$string['action:generate_image:model'] = 'AI model';
$string['action:generate_image:model_help'] = 'The model that generates the image. The list comes from OpenRouter\'s image catalogue and is cached for a day; purge the site caches to refresh it sooner. Choose "Other model" to enter any image model ID by hand. Image models are billed per image, so consider setting rate limits on this provider instance.';
$string['action:generate_image:resolution'] = 'Resolution';
$string['action:generate_image:resolution_help'] = 'The resolution tier to request. Only 19 of the image models accept one, and each accepts its own set of tiers, so this is sent only when the chosen model accepts the tier you pick. Otherwise the model\'s own default resolution applies. Higher tiers cost more.';
$string['action:generate_text:endpoint'] = 'API endpoint';
$string['action:generate_text:endpoint_help'] = 'The OpenRouter chat completions endpoint. Leave this alone unless you are routing requests through a proxy.';
$string['action:generate_text:model'] = 'AI model';
$string['action:generate_text:model_help'] = 'The model that generates the text. The list is read from OpenRouter and cached for a day; purge the site caches to refresh it sooner. Choose "Other model" to enter any model ID by hand. "Auto Router" lets OpenRouter pick a model per request, which means you cannot predict the cost or the quality in advance.';
$string['action:generate_text:systeminstruction'] = 'System instruction';
$string['action:generate_text:systeminstruction_help'] = 'Sent to the model ahead of the user prompt. Editing this is not recommended unless you have a specific reason.';
$string['action:generate_text:temperature'] = 'Temperature';
$string['action:generate_text:temperature_help'] = 'How much randomness the model is allowed. Low values give predictable, repeatable text; high values give more variety. Accepted range is 0 to 2.';
$string['action:summarise_text:endpoint'] = 'API endpoint';
$string['action:summarise_text:endpoint_help'] = 'The OpenRouter chat completions endpoint. Leave this alone unless you are routing requests through a proxy.';
$string['action:summarise_text:maxwords'] = 'Maximum words';
$string['action:summarise_text:maxwords_help'] = 'Summaries are asked to stay within this many words, and are trimmed to it if the model overruns. Set it to 0 to leave the length entirely to the model.';
$string['action:summarise_text:model'] = 'AI model';
$string['action:summarise_text:model_help'] = 'The model that produces the summary. The list is read from OpenRouter and cached for a day; purge the site caches to refresh it sooner. Choose "Other model" to enter any model ID by hand.';
$string['action:summarise_text:systeminstruction'] = 'System instruction';
$string['action:summarise_text:systeminstruction_help'] = 'Sent to the model ahead of the text being summarised. Editing this is not recommended unless you have a specific reason.';
$string['action:summarise_text:temperature'] = 'Temperature';
$string['action:summarise_text:temperature_help'] = 'How much randomness the model is allowed. Low values give predictable, repeatable summaries. Accepted range is 0 to 2.';
$string['actionsettingsnotice'] = 'The model, endpoint, temperature and system instruction are configured for each action separately. Save this page first, then open <strong>Actions</strong> on this provider instance and edit <strong>Generate text</strong> and <strong>Summarise text</strong>.';
$string['apikey'] = 'OpenRouter API key';
$string['apikey_help'] = 'Get a key from your <a href="https://openrouter.ai/keys" target="_blank">OpenRouter API keys</a> page.';
$string['custommodel'] = 'Other model';
$string['custommodel_help'] = 'A model ID exactly as OpenRouter publishes it, for example <code>anthropic/claude-sonnet-5</code>. Only used when the model chooser above is set to "Other model". See the <a href="https://openrouter.ai/models" target="_blank">OpenRouter model list</a>.';
$string['error:httpstatus'] = 'OpenRouter returned HTTP {$a}.';
$string['error:maxwords'] = 'Enter a whole number of words, or 0 for no limit.';
$string['error:noimagereturned'] = 'OpenRouter did not return an image.';
$string['error:requestfailed'] = 'The request to OpenRouter could not be completed.';
$string['error:temperaturerange'] = 'Enter a number between {$a->min} and {$a->max}.';
$string['error:unexpectedresponse'] = 'OpenRouter returned a response this plugin could not read.';
$string['imagecapabilities'] = 'Parameters this model accepts';
$string['imagecapabilities_detail'] = '<code>{$a->model}</code> accepts: <strong>{$a->params}</strong>. Settings outside that list are not sent, because a model rejects a request carrying a parameter it does not support. This reflects the saved model, so it updates when you save.';
$string['imagestylesuffix'] = 'Style: {$a}.';
$string['modellistunavailable'] = 'The model list could not be read from OpenRouter, so only a short built-in list is shown. Choose "Other model" to enter any model ID by hand.';
$string['pluginname'] = 'OpenRouter AI Provider (Next Gen Technologies)';
$string['privacy:metadata'] = 'This plugin does not store any personal data in Moodle.';
$string['privacy:metadata:aiprovider_n3xtopenrouter:aspectratio'] = 'The requested aspect ratio. When generating images.';
$string['privacy:metadata:aiprovider_n3xtopenrouter:externalpurpose'] = 'This information is sent to the OpenRouter API so that a response can be generated. Your OpenRouter account settings determine how OpenRouter stores and retains it. No user data is explicitly sent to OpenRouter, and none is stored in Moodle by this plugin.';
$string['privacy:metadata:aiprovider_n3xtopenrouter:model'] = 'The model used to generate the response.';
$string['privacy:metadata:aiprovider_n3xtopenrouter:prompttext'] = 'The user entered text prompt used to generate the response.';
$string['privacy:metadata:aiprovider_n3xtopenrouter:quality'] = 'The requested image quality. When generating images.';
$string['summaryconstraint'] = 'Limit the summary to a maximum of {$a} words. Write it as a single paragraph, with no bullet points and no line breaks.';
$string['testaiconfiguration'] = 'Test AI configuration';
$string['testconnectionconfirm'] = 'This sends one real request to OpenRouter using your configured API key, and your account will be charged for it. Continue?';
$string['testconnectiondetail'] = 'Detail';
$string['testconnectionfailed'] = 'The request failed with code {$a->code}: {$a->message}';
$string['testconnectionmodel'] = 'Model that answered';
$string['testconnectionresponse'] = 'Response';
$string['testconnectionsuccess'] = 'OpenRouter answered successfully. The provider is configured correctly.';
$string['testconnectiontokens'] = 'Tokens (prompt / completion)';
$string['testconnectionvalue'] = 'Value';
