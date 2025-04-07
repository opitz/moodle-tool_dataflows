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

namespace tool_dataflows\local\step;

use stdClass;
use ArrayIterator;
use MoodleQuickForm;
use moodle_exception;
use tool_dataflows\local\execution\iterators\iterator;
use tool_dataflows\local\execution\iterators\dataflow_iterator;

/**
 * Array reader
 *
 * Supplies a stream sourced from an expression/variable.
 *
 * @package tool_dataflows
 * @author Jason den Dulk <jasondendulk@catalyst-au.net>
 * @copyright 2025 Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader_array extends reader_step {
    /**
     * Extra configuration settings used by array reader.
     *
     * @return array[]
     */
    public static function form_define_fields(): array {
        return [
            'source' => ['type' => PARAM_TEXT, 'required' => true],
            'separator' => ['type' => PARAM_TEXT, 'required' => true],
            'enclosure' => ['type' => PARAM_TEXT, 'required' => true],
        ];
    }

    /**
     * Adds configuration settings for array reader.
     *
     * @param MoodleQuickForm $mform
     */
    public function form_add_custom_inputs(MoodleQuickForm &$mform) {
        // Array source.
        $mform->addElement('text', 'config_source', get_string('reader_array:source', 'tool_dataflows'));
        $mform->addElement('static', 'config_source_help', '',  get_string('reader_array:source_help', 'tool_dataflows'));

        // Separator.
        $mform->addElement('text', 'config_separator', get_string('reader_array:separator', 'tool_dataflows'));
        $mform->addElement('static', 'config_separator_help', '',  get_string('reader_array:separator_help', 'tool_dataflows'));

        // Enclosure.
        $mform->addElement('text', 'config_enclosure', get_string('reader_array:enclosure', 'tool_dataflows'));
        $mform->addElement('static', 'config_enclosure_help', '',  get_string('reader_array:enclosure_help', 'tool_dataflows'));
    }

    /**
     * Gets the defaults for the step specific configuration settings.
     *
     * @param stdClass $data
     * @return stdClass
     */
    public function form_get_default_data(stdClass $data): stdClass {
        $defaultdata = parent::form_get_default_data($data);
        $defaultdata->config_separator ??= ',';
        $defaultdata->config_enclosure ??= '"';
        return $defaultdata;
    }

    /**
     * Validates the step specific configuration settings.
     *
     * @param object $config
     * @return true|\lang_string[] true if valid, an array of errors otherwise
     */
    public function validate_config($config) {
        $errors = [];
        if (strlen($config->separator) != 1) {
            $errors['config_separator'] = get_string('reader_array:separator_must_be_one_char', 'tool_dataflows', '', true);
        }
        if (strlen($config->enclosure) != 1) {
            $errors['config_enclosure'] = get_string('reader_array:enclosure_must_be_one_char', 'tool_dataflows', '', true);
        }
        return count($errors) ? $errors : true;
    }

    /**
     * Gets the iterator for the flow, based on configurations.
     *
     * @return iterator
     */
    public function get_iterator(): iterator {
        $config = $this->get_variables()->get('config');
        $source = $config->source;
        if (is_string($source)) {
            $source = str_getcsv($source, $config->separator, $config->enclosure, '');
        }
        if (!is_iterable($source)) {
            throw new moodle_exception('reader_array:must_be_array_or_string', 'tool_dataflows');
        }
        return new dataflow_iterator($this->enginestep, new ArrayIterator($source));
    }
}

