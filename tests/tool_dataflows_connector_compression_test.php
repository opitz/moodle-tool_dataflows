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

namespace tool_dataflows;

use Symfony\Component\Yaml\Yaml;
use tool_dataflows\local\execution\engine;
use tool_dataflows\local\step\connector_compression;
use tool_dataflows\local\step\connector_copy_file;

/**
 * Unit test for the compression connector step.
 *
 * @package   tool_dataflows
 * @author    Matthew Hilton <matthewhilton@catalyst-au.net>
 * @copyright 2023, Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \tool_dataflows\local\step\connector_compression
 */
final class tool_dataflows_connector_compression_test extends \advanced_testcase {
    /** @var string base test directory for files when compressing * */
    private $basedircmp;
    /** @var string base test directory for files when decompressing * */
    private $basedirdecmp;

    /**
     * Sets up tests
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $tempdir = make_temp_directory('tool_dataflows');
        $this->basedircmp = make_unique_writable_directory($tempdir);
        $this->basedirdecmp = make_unique_writable_directory($tempdir);
        set_config('permitted_dirs', $this->basedircmp . PHP_EOL . $this->basedirdecmp, 'tool_dataflows');
        set_config('gzip_exec_path', '/usr/bin/gzip', 'tool_dataflows');
    }

    protected function tearDown(): void {
        parent::tearDown();
        $this->basedircmp = null;
        $this->basedirdecmp = null;
    }

    /**
     * Creates a test dataflow
     *
     * This dataflow compresses a file, then copies it, then decompresses the copied file.
     *
     * @param string $compressfrom Original source file
     * @param string $compressto Output file for compression
     * @param string $decompressfrom File to be decompressed ($compressto copied)
     * @param string $decompressto Output file for decompression
     * @param string $method Compression algorithm to use.
     * @return dataflow
     */
    private function create_test_dataflow(
        string $compressfrom,
        string $compressto,
        string $decompressfrom,
        string $decompressto,
        string $method = 'gzip'
    ): dataflow {
        $dataflow = new dataflow();
        $dataflow->name = 'compression-connector-test';
        $dataflow->save();

        $compress = new step();
        $compress->config = Yaml::dump([
            'from' => $compressfrom,
            'to' => $compressto,
            'method' => $method,
            'command' => 'compress',
        ]);

        $compress->name = 'compress';
        $compress->type = connector_compression::class;

        $dataflow->add_step($compress);

        // We use a copy so that the decompression files will not interfere with compression files.
        $copyfile = new step();
        $copyfile->config = Yaml::dump([
            'from' => $compressto,
            'to' => $decompressfrom,
        ]);
        $copyfile->depends_on([$compress]);
        $copyfile->name = 'copyfile';
        $copyfile->type = connector_copy_file::class;

        $dataflow->add_step($copyfile);

        $decompress = new step();
        $decompress->config = Yaml::dump([
            'from' => $decompressfrom,
            'to' => $decompressto,
            'method' => $method,
            'command' => 'decompress',
        ]);

        $decompress->depends_on([$copyfile]);
        $decompress->name = 'decompress';
        $decompress->type = connector_compression::class;

        $dataflow->add_step($decompress);
        return $dataflow;
    }

    /**
     * Provider for test_gzip_compression_decompression.
     *
     * @return array[]
     */
    public static function gzip_compression_decompression_provider(): array {
        return [
            'different filenames' => ['input.txt', 'output.txt.gz', 'output_data.txt'],
            'same filenames for compress' => ['input.txt', 'input.txt.gz', 'output_data.txt'],
            'same filenames for decompress' => ['input.txt', 'output.txt.gz', 'output.txt'],
            'same filenames' => ['input.txt', 'input.txt.gz', 'input.txt'],
        ];
    }

    /**
     * Test compression
     *
     * @param string $compressfrom Source file name
     * @param string $compressto Destination file name for compression. Doubles as the source name for decompression.
     * @param string $decompressto Destination file name fro decompression.
     * @dataProvider gzip_compression_decompression_provider
     */
    public function test_gzip_compression_decompression(
        string $compressfrom,
        string $compressto,
        string $decompressto
    ): void {
        // Ensure gzip is installed, otherwise we should skip the test.
        if (!is_executable(get_config('tool_dataflows', 'gzip_exec_path'))) {
            $this->markTestSkipped('gzip is not installed');
            return;
        }

        // Source file for compression.
        $compressfrom = $this->basedircmp . '/' . $compressfrom;
        // Destination file for compression.
        $compressto = $this->basedircmp . '/' . $compressto;
        // Source file for decompression.
        $decompressfrom = $this->basedirdecmp . '/' . $compressto;
        // Destination file for decompression.
        $decompressto = $this->basedirdecmp . '/' . $decompressto;

        $datatowrite = 'testdata';
        file_put_contents($compressfrom, $datatowrite);

        // The original source file should exist (we just wrote to it), but the other files should NOT exist yet.
        $this->assertTrue(is_file($compressfrom));
        $this->assertFalse(is_file($compressto));
        $this->assertFalse(is_file($decompressfrom));
        $this->assertFalse(is_file($decompressto));

        $dataflow = $this->create_test_dataflow(
            $compressfrom,
            $compressto,
            $decompressfrom,
            $decompressto
        );

        ob_start();
        $engine = new engine($dataflow, false, false);
        $engine->execute();
        ob_get_clean();

        // Check that the output files exist and also that the input files were left intact.
        $this->assertTrue(is_file($compressfrom));
        $this->assertTrue(is_file($compressto));
        $this->assertTrue(is_file($decompressfrom));
        $this->assertTrue(is_file($decompressto));

        // Check that the originally written data ended up the same in the decompressed file.
        $finaldata = file_get_contents($decompressto);
        $this->assertEquals($datatowrite, $finaldata);

        $vars = $engine->get_variables_root()->get('steps.compress.vars');
        $this->assertTrue($vars->success);

        $vars = $engine->get_variables_root()->get('steps.decompress.vars');
        $this->assertTrue($vars->success);
    }

    /**
     * Tests gzip validation
     */
    public function test_gzip_validation(): void {
        // Ensure gzip is installed, otherwise we should skip the test.
        if (!is_executable(get_config('tool_dataflows', 'gzip_exec_path'))) {
            $this->markTestSkipped('gzip is not installed');
            return;
        }

        $compressfrom = $this->basedircmp . '/input.txt';
        $compressto = $this->basedircmp . '/output.txt.gz';
        $decompressfrom = $this->basedirdecmp . '/output.txt.gz';
        $decompressto = $this->basedirdecmp . '/output_new.txt';

        $dataflow = $this->create_test_dataflow($compressfrom, $compressto, $decompressfrom, $decompressto);
        $step = $dataflow->get_steps()->compress;

        // Initially the default gzip should be executable.
        // Which means the step is ready to run.
        $this->assertTrue($step->steptype->validate_for_run());

        // Break the gzip config.
        set_config('gzip_exec_path', '/not/a/real/path', 'tool_dataflows');
        $validation = $step->steptype->validate_for_run();

        $this->assertTrue(is_array($validation));
        $this->assertTrue(!empty($validation['config_method']));
    }
}

