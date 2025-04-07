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

namespace tool_dataflows;

use Symfony\Component\Yaml\Yaml;
use tool_dataflows\local\execution\engine;
use tool_dataflows\local\step\reader_array;
use tool_dataflows\local\step\writer_stream;

/**
 * Unit tests for array reader
 *
 * @covers \tool_dataflows\local\step\reader_array
 * @package tool_dataflows
 * @author Jason den Dulk <jasondendulk@catalyst-au.net>
 * @copyright 2025 Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_dataflows_reader_array_test extends \advanced_testcase {
    /**
     * Tests the reader with an array source.
     *
     * @return void
     */
    public function test_with_array(): void {
        $this->resetAfterTest();

        $dir = make_temp_directory('tool_dataflows');
        set_config('permitted_dirs', $dir, 'tool_dataflows');

        $expected = ['a', 'b', 'c,d', 'e'];
        $source = '${{dataflow.vars.source}}';

        [$dataflow, $steps, $outputpath] = $this->create_dataflow($dir, $source);

        $dataflow->set_dataflow_vars(['source' => $expected]);

        // Execute.
        ob_start();
        $engine = new engine($dataflow);
        $engine->execute();
        ob_get_clean();

        $output = json_decode(file_get_contents($outputpath));

        // Expected output; Add a header line.
        $this->assertEquals($expected, $output);
    }

    /**
     * Tests the reader with a string source.
     *
     * @return void
     */
    public function test_with_string(): void {
        $this->resetAfterTest();

        $dir = make_temp_directory('tool_dataflows');
        set_config('permitted_dirs', $dir, 'tool_dataflows');

        $source = 'a,b,"c,d",e';
        $expected = ['a', 'b', 'c,d', 'e'];

        [$dataflow, $steps, $outputpath] = $this->create_dataflow($dir, $source);

        // Execute.
        ob_start();
        $engine = new engine($dataflow);
        $engine->execute();
        ob_get_clean();

        $output = json_decode(file_get_contents($outputpath));

        // Expected output; Add a header line.
        $this->assertEquals($expected, $output);
    }

    /**
     * Dataflow creation helper function
     *
     * @param string $dir
     * @param string $source
     * @return  array dataflow, steps and output filename
     */
    protected function create_dataflow(string $dir, string $source): array {
        $dataflow = new dataflow();
        $dataflow->name = 'testflow';
        $dataflow->enabled = true;
        $dataflow->save();

        $steps = [];

        // Reader.
        $reader = new step();
        $reader->name = 'reader';
        $reader->type = reader_array::class;
        $reader->config = Yaml::dump([
            'source' => $source,
            'separator' => ',',
            'enclosure' => '"',
        ]);
        $dataflow->add_step($reader);
        $steps[$reader->id] = $reader;

        // Writer.
        $outputpath = tempnam($dir, 'tool_dataflows');
        $writer = new step();
        $writer->name = 'stream-writer';
        $writer->type = writer_stream::class;
        $writer->config = Yaml::dump([
            'format' => 'json',
            'streamname' => $outputpath,
        ]);
        $writer->depends_on([$reader]);
        $dataflow->add_step($writer);
        $steps[$writer->id] = $writer;

        return [$dataflow, $steps, $outputpath];
    }
}
