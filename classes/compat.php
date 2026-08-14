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

/**
 * Compatibility helpers for different PHP/Moodle environments.
 *
 * @package    aiprovider_n3xtopenrouter
 * @copyright  2026 Alessio Giustini, Next Gen Technologies Italia <https://n3xtgentech.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compat {
    /**
     * Ensure Moodle's bundled PEAR is used (instead of a system PEAR on include_path).
     *
     * Some server images put system PEAR ahead of Moodle, and old PEAR versions can
     * fatally error on PHP 8+ when called statically (e.g. PEAR::getStaticProperty()).
     *
     * This must run before HTML_QuickForm/PEAR is first loaded.
     */
    public static function ensure_moodle_pear_loaded(): void {
        global $CFG;

        if (empty($CFG->libdir)) {
            return;
        }

        $peardir = $CFG->libdir . '/pear';
        $pearfile = $peardir . '/PEAR.php';
        if (!is_dir($peardir) || !is_file($pearfile)) {
            return;
        }

        $includepath = get_include_path();
        if (strpos($includepath, $peardir) !== 0) {
            set_include_path($peardir . PATH_SEPARATOR . $includepath);
        }

        if (!class_exists('PEAR', false)) {
            require_once($pearfile);
        }
    }
}
