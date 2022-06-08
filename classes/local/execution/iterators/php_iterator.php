<?php
// This file is part of Moodle - https://moodle.org/
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

namespace tool_dataflows\local\execution\iterators;

use tool_dataflows\local\execution\engine;
use tool_dataflows\local\execution\flow_engine_step;

/**
 * A mapping iterator that takes a PHP iterator as a source.
 *
 * @package   tool_dataflows
 * @author    Jason den Dulk <jasondendulk@catalyst-au.net>
 * @copyright 2022, Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class php_iterator implements iterator {
    protected $steptype;
    protected $finished = false;
    protected $input;
    protected $step;

    protected $iterationcount = 0;

    /**
     * @param flow_engine_step $step The step the iterator is for.
     * @param \Iterator $input The source for this reader, as a PHP iterator.
     */
    public function __construct(flow_engine_step $step, \Iterator $input) {
        $this->step = $step;
        $this->input = $input;
        $this->steptype = $step->steptype;
    }

    /**
     * True if the iterator has no more values to provide.
     *
     * @return bool
     */
    public function is_finished(): bool {
        return $this->finished;
    }

    /**
     * True if the iterator is capable (or allowed) of supplying a value.
     *
     * @return bool
     */
    public function is_ready(): bool {
        return !$this->finished && $this->input->valid();
    }

    /**
     * Terminate the iterator immediately.
     */
    public function abort() {
        $this->finished = true;
    }

    /**
     * Next item in the stream.
     *
     * @return object|bool A JSON compatible object, or false if nothing returned.
     */
    public function next() {
        if ($this->finished) {
            return false;
        }

        // Only performs this check on the first iteration, and aborts if the
        // iterator's position is valid (e.g. has no data).
        if ($this->iterationcount === 0 && !$this->input->valid()) {
            $this->step->log('Aborting at iteration ' . $this->iterationcount . '. No data?');
            $this->abort();
            return false;
        }

        $value = $this->input->current();
        $this->input->next();
        if (!$this->input->valid()) {
            $this->abort();
        }
        $newvalue = $this->steptype->execute($value);
        ++$this->iterationcount;
        $this->step->log('Iteration ' . $this->iterationcount . ': ' . json_encode($newvalue));
        return $newvalue;
    }
}
