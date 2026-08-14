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

use Psr\Http\Message\ResponseInterface;

/**
 * Process a text summarisation action against OpenRouter.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2024 Marcus Green
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_summarise_text extends process_generate_text {
    /**
     * The configured word cap for a summary, or 0 when uncapped.
     *
     * @return int
     */
    protected function get_max_words(): int {
        $maxwords = (int) $this->get_action_setting('maxwords', defaults::MAXWORDS);
        return max(0, $maxwords);
    }

    #[\Override]
    protected function get_system_instruction(): string {
        $instruction = parent::get_system_instruction();

        $maxwords = $this->get_max_words();
        if ($maxwords > 0) {
            // Asked for in the prompt and enforced again on the response below:
            // models treat a length request as a hint, not a contract.
            $instruction .= "\n\n" . get_string(
                'summaryconstraint',
                'aiprovider_n3xtopenrouter',
                $maxwords
            );
        }

        return $instruction;
    }

    #[\Override]
    protected function handle_api_success(ResponseInterface $response): array {
        $result = parent::handle_api_success($response);
        if (empty($result['success']) || empty($result['generatedcontent'])) {
            return $result;
        }

        $maxwords = $this->get_max_words();
        if ($maxwords === 0) {
            return $result;
        }

        // Force a single paragraph.
        $content = (string) $result['generatedcontent'];
        $content = preg_replace("/\R+/u", ' ', $content);
        $content = preg_replace('/\s{2,}/u', ' ', trim($content));

        // Enforce the word cap the model was asked to respect.
        $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($words) && count($words) > $maxwords) {
            $content = implode(' ', array_slice($words, 0, $maxwords));
        }

        $result['generatedcontent'] = $content;
        return $result;
    }
}
