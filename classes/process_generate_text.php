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

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Process a text generation action against OpenRouter.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_text extends abstract_processor {
    #[\Override]
    protected function create_request_object(string $userid): RequestInterface {
        $userobj = new \stdClass();
        $userobj->role = 'user';
        $userobj->content = $this->action->get_configuration('prompttext');

        $requestobj = new \stdClass();
        $requestobj->model = $this->get_model();
        $requestobj->user = $userid;
        $requestobj->temperature = $this->get_temperature();

        // If there is a system string available, use it.
        $systeminstruction = $this->get_system_instruction();
        if (!empty($systeminstruction)) {
            $systemobj = new \stdClass();
            $systemobj->role = 'system';
            $systemobj->content = $systeminstruction;
            $requestobj->messages = [$systemobj, $userobj];
        } else {
            $requestobj->messages = [$userobj];
        }

        return new Request(
            method: 'POST',
            uri: '',
            body: json_encode($requestobj),
            headers: [
                'Content-Type' => 'application/json',
            ],
        );
    }

    #[\Override]
    protected function handle_api_success(ResponseInterface $response): array {
        $bodyobj = json_decode((string) $response->getBody()->getContents());

        $content = $bodyobj->choices[0]->message->content ?? null;
        if (!is_string($content) || trim($content) === '') {
            return [
                'success' => false,
                'errorcode' => -1,
                'errormessage' => get_string('error:unexpectedresponse', 'aiprovider_n3xtopenrouter'),
            ];
        }

        return [
            'success' => true,
            'id' => $bodyobj->id ?? null,
            // OpenRouter only forwards system_fingerprint for some upstream
            // providers, so it is genuinely optional rather than a fault.
            'fingerprint' => $bodyobj->system_fingerprint ?? null,
            'generatedcontent' => $content,
            'finishreason' => $bodyobj->choices[0]->finish_reason ?? 'stop',
            'prompttokens' => $bodyobj->usage->prompt_tokens ?? 0,
            'completiontokens' => $bodyobj->usage->completion_tokens ?? 0,
            // Report the model that actually answered, not the one requested.
            // With openrouter/auto they differ, and the resolved model is the
            // only way an admin can tell what the site is spending money on.
            'model' => $bodyobj->model ?? $this->get_model(),
        ];
    }
}
