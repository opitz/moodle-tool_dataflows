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

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use tool_dataflows\local\execution\engine;
use tool_dataflows\local\step\connector_adl;

/**
 * Tests for ADL connector functionality
 *
 * @package    tool_dataflows
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_dataflows_connector_adl_test extends \advanced_testcase {
    /** @var connector_adl ADL connector using the trait */
    private connector_adl $adlconnector;

    /** @var MockHandler Guzzle mock handler */
    private MockHandler $mockhandler;

    /** @var string Temporary directory for file operations */
    private string $tempdir;

    /** @var Client HTTP client used for testing */
    private Client $httpclient;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Create a temporary directory for file operations.
        $this->tempdir = sys_get_temp_dir() . '/adl_test_' . uniqid();
        mkdir($this->tempdir);

        // Initialise the ADL connector.
        $this->adlconnector = new connector_adl();
        $reflection = new \ReflectionClass($this->adlconnector);

        // Set up mock HTTP client and required properties using Reflection.
        $this->mockhandler = new MockHandler();
        $handlerstack = HandlerStack::create($this->mockhandler);
        $this->httpclient = new Client(['handler' => $handlerstack]);

        // Use reflection to set private properties.
        $httpclientprop = $reflection->getProperty('httpclient');
        $httpclientprop->setAccessible(true);
        $httpclientprop->setValue($this->adlconnector, $this->httpclient);

        $accounturlprop = $reflection->getProperty('accounturl');
        $accounturlprop->setAccessible(true);
        $accounturlprop->setValue($this->adlconnector, 'https://teststorage.blob.core.windows.net');

        $accountnameprop = $reflection->getProperty('accountname');
        $accountnameprop->setAccessible(true);
        $accountnameprop->setValue($this->adlconnector, 'teststorage');

        $accesskeyprop = $reflection->getProperty('accesskey');
        $accesskeyprop->setAccessible(true);
        $accesskeyprop->setValue($this->adlconnector, base64_encode('testkey'));
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        // Remove temporary directory and files.
        if (file_exists($this->tempdir)) {
            $files = glob($this->tempdir . '/*');
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempdir);
        }

        parent::tearDown();
    }

    /**
     * Test path identification for ADL
     *
     * @covers \tool_dataflows\local\step\connector_adl::is_adl_path
     */
    public function test_is_adl_path(): void {
        $this->assertTrue($this->adlconnector->is_adl_path('adl://container/file.txt'));
        $this->assertFalse($this->adlconnector->is_adl_path('/local/path/file.txt'));
    }

    /**
     * Test path resolution
     *
     * @covers \tool_dataflows\local\step\connector_adl::resolve_path
     */
    public function test_resolve_path(): void {
        $this->assertEquals(
            '/container/file.txt',
            $this->adlconnector->resolve_path('adl://container/file.txt', true)
        );
    }

    /**
     * Test blob upload functionality
     *
     * @covers \tool_dataflows\local\step\connector_adl::upload_blob
     */
    public function test_upload_blob(): void {
        $this->mockhandler->append(new Response(201));

        $result = $this->adlconnector->upload_blob(
            '/container/test.txt',
            'Test content',
            'text/plain'
        );

        $this->assertTrue($result);
    }

    /**
     * Test blob download functionality
     *
     * @covers \tool_dataflows\local\step\connector_adl::download_blob
     */
    public function test_download_blob(): void {
        $testcontent = 'Test content';
        $this->mockhandler->append(new Response(200, [], $testcontent));

        $result = $this->adlconnector->download_blob('/container/test.txt');
        $this->assertEquals($testcontent, $result);
    }

    /**
     * Test blob copy functionality
     *
     * @covers \tool_dataflows\local\step\connector_adl::copy_blob
     */
    public function test_copy_blob(): void {
        $this->mockhandler->append(new Response(202));

        $result = $this->adlconnector->copy_blob(
            '/container/source.txt',
            '/container/target.txt'
        );

        $this->assertTrue($result);
    }

    /**
     * Test configuration validation
     *
     * @covers \tool_dataflows\local\step\connector_adl::validate_config
     */
    public function test_validate_config(): void {
        $config = new stdClass();
        $config->accountname = 'teststorage';
        $config->accesskey = 'testkey';
        $config->source = 'adl://container/source.txt';
        $config->target = '/local/target.txt';

        $result = $this->adlconnector->validate_config($config);
        $this->assertTrue($result);

        // Test invalid config.
        $config->source = '';
        $result = $this->adlconnector->validate_config($config);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('config_source', $result);
    }

    /**
     * Test validation for both local paths (should fail)
     *
     * @covers \tool_dataflows\local\step\connector_adl::validate_config
     */
    public function test_validate_config_both_local_paths(): void {
        $config = new stdClass();
        $config->accountname = 'teststorage';
        $config->accesskey = 'testkey';
        $config->source = '/local/source.txt';
        $config->target = '/local/target.txt';

        $result = $this->adlconnector->validate_config($config);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('config_source', $result);
        $this->assertArrayHasKey('config_target', $result);
    }

    /**
     * Test directory validation for local source (should fail)
     *
     * @covers \tool_dataflows\local\step\connector_adl::validate_config
     */
    public function test_validate_config_local_directory(): void {
        // Create a temporary directory.
        $tempdir = sys_get_temp_dir() . '/adl_test_' . uniqid();
        mkdir($tempdir);

        $config = new stdClass();
        $config->accountname = 'teststorage';
        $config->accesskey = 'testkey';
        $config->source = $tempdir;
        $config->target = 'adl://container/target.txt';

        $result = $this->adlconnector->validate_config($config);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('config_source', $result);

        // Clean up.
        rmdir($tempdir);
    }

    /**
     * Test authorization header generation
     *
     * @covers \tool_dataflows\local\step\connector_adl::generate_auth_header
     */
    public function test_generate_auth_header(): void {
        $method = new \ReflectionMethod($this->adlconnector, 'generate_auth_header');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->adlconnector,
            'PUT',
            '13',
            'text/plain',
            'Wed, 24 Jan 2025 12:00:00 GMT',
            '/container/test.txt'
        );

        $this->assertStringStartsWith('SharedKey teststorage:', $result);
    }

    /**
     * Test has side effect
     * Logic is copied from tool_dataflows_connector_s3_test.php.
     *
     * @dataProvider has_side_effect_provider
     * @covers \tool_dataflows\local\step\connector_adl::has_side_effect
     * @param string $source
     * @param string $target
     * @param bool $expected
     */
    public function test_has_side_effect(string $source, string $target, bool $expected): void {
        set_config(
            'global_vars',
            Yaml::dump([
                'abs' => '/test/target.csv',
                'rel' => 'test/target.csv',
            ]),
            'tool_dataflows'
        );

        $config = [
            'accountname' => 'teststorage',
            'accesskey' => 'SOMEKEY',
            'source' => $source,
            'target' => $target,
        ];

        $dataflow = new dataflow();
        $dataflow->name = 'dataflow';
        $dataflow->enabled = true;
        $dataflow->save();

        $step = new step();
        $step->name = 'connector_adl';
        $step->type = connector_adl::class;
        $step->config = Yaml::dump($config);
        $dataflow->add_step($step);
        $steptype = $step->steptype;

        $this->assertEquals($expected, $steptype->has_side_effect());
    }

    /**
     * Data provider for test_has_side_effect().
     * Logic is copied from tool_dataflows_connector_s3_test.php.
     *
     * @return array[]
     */
    public static function has_side_effect_provider(): array {
        return [
            ['adl://test/source.csv', 'adl://test/target.csv', true],
            ['adl://test/source.csv', 'test/target.csv', false],
            ['test/source.csv', 'adl://test/target.csv', true],
            ['adl://test/source.csv', '${{global.vars.abs}}', true],
            ['adl://test/source.csv', '${{global.vars.rel}}', false],
            ['test/source.csv', 'adl://${{global.vars.rel}}', true],
        ];
    }

    /**
     * Tests run validation.
     * Absolute paths are not allowed as no permitted directories are set.
     * Logic is copied from tool_dataflows_connector_s3_test.php.
     *
     * @dataProvider validate_for_run_provider
     * @covers \tool_dataflows\local\step\connector_adl::validate_for_run
     * @param string $source
     * @param string $target
     * @param array|true $expected
     */
    public function test_validate_for_run(string $source, string $target, $expected): void {
        $config = [
            'accountname' => 'teststorage',
            'accesskey' => 'SOMEKEY',
            'source' => $source,
            'target' => $target,
        ];

        $dataflow = new dataflow();
        $dataflow->enabled = true;
        $dataflow->name = 'dataflow';
        $dataflow->save();
        $step = new step();
        $step->name = 'name';
        $step->type = 'tool_dataflows\local\step\connector_adl';
        $step->config = Yaml::dump($config);
        $dataflow->add_step($step);
        $steptype = $step->steptype;

        set_config('permitted_dirs', '', 'tool_dataflows');
        $this->assertEquals($expected, $steptype->validate_for_run());
    }

    /**
     * Provider method for test_validate_for_run().
     * Logic is copied from tool_dataflows_connector_s3_test.php.
     *
     * @return array[]
     * @throws \coding_exception
     */
    public static function validate_for_run_provider(): array {
        $adlsourcefile = 'adl://test/source.csv';
        $relativefile = 'test/source.csv';
        $absolutefile = '/tmp/source.csv';
        $errormsg = get_string('path_invalid', 'tool_dataflows', $absolutefile, true);
        return [
            [$adlsourcefile, $adlsourcefile, true],
            [$adlsourcefile, $relativefile, true],
            [$relativefile, $adlsourcefile, true],
            [$absolutefile, $adlsourcefile, ['config_source' => $errormsg]],
            [$adlsourcefile, $absolutefile, ['config_target' => $errormsg]],
        ];
    }

    /**
     * Extra tests for run validation.
     * The validation should pass if the absolute path is within the permitted directories.
     * Logic is copied from tool_dataflows_connector_s3_test.php.
     *
     * @covers \tool_dataflows\local\step\connector_adl::validate_for_run
     */
    public function test_validate_for_run_extra(): void {
        $config = [
            'accountname' => 'teststorage',
            'accesskey' => 'SOMEKEY',
            'source' => 'adl://test/source.csv',
            'target' => '/tmp/source.csv',
        ];

        $dataflow = new dataflow();
        $dataflow->enabled = true;
        $dataflow->name = 'dataflow';
        $dataflow->save();
        $step = new step();
        $step->name = 'name';
        $step->type = connector_adl::class;
        $step->config = Yaml::dump($config);
        $dataflow->add_step($step);
        $steptype = $step->steptype;

        set_config('permitted_dirs', '/tmp', 'tool_dataflows');
        $this->assertTrue($steptype->validate_for_run());

        $config['source'] = '/tmp/source.csv';
        $config['target'] = 'adl://test/source.csv';
        $step->config = Yaml::dump($config);
        $this->assertTrue($steptype->validate_for_run());
    }

    /**
     * Data provider for test_execute().
     *
     * @return array[]
     */
    public static function execute_provider(): array {
        return [
            'local_to_adl' => [
                'source' => 'local_file',
                'target' => 'adl://container/target.txt',
                'responses' => [new Response(201)],
                'isdryrun' => false,
                'expectexception' => false,
                'checkfile' => false,
                'expectedcontent' => null,
            ],
            'adl_to_local' => [
                'source' => 'adl://container/source.txt',
                'target' => 'local_file',
                'responses' => [new Response(200, [], 'Downloaded test content')],
                'isdryrun' => false,
                'expectexception' => false,
                'checkfile' => true,
                'expectedcontent' => 'Downloaded test content',
            ],
            'adl_to_adl' => [
                'source' => 'adl://container/source.txt',
                'target' => 'adl://container/target.txt',
                'responses' => [new Response(202)],
                'isdryrun' => false,
                'expectexception' => false,
                'checkfile' => false,
                'expectedcontent' => null,
            ],
            'dry_run' => [
                'source' => 'local_file',
                'target' => 'adl://container/target.txt',
                'responses' => [],
                'isdryrun' => true,
                'expectexception' => false,
                'checkfile' => false,
                'expectedcontent' => null,
            ],
            'exception_handling' => [
                'source' => 'local_file',
                'target' => 'adl://container/target.txt',
                'responses' => [
                    new RequestException(
                        'Error uploading blob',
                        new Request('PUT', 'https://teststorage.blob.core.windows.net/container/target.txt')
                    ),
                ],
                'isdryrun' => false,
                'expectexception' => true,
                'checkfile' => false,
                'expectedcontent' => null,
            ],
        ];
    }

    /**
     * Test execute method with various scenarios
     *
     * @dataProvider execute_provider
     * @covers \tool_dataflows\local\step\adl_trait::execute
     * @param string $source Source path
     * @param string $target Target path
     * @param array $responses Mock responses to queue
     * @param bool $isdryrun Whether to run in dry run mode
     * @param bool $expectexception Whether to expect an exception to be caught
     * @param bool $checkfile Whether to check file content after execution
     * @param string|null $expectedcontent Expected content if checking file
     */
    public function test_execute(
        string $source,
        string $target,
        array $responses,
        bool $isdryrun,
        bool $expectexception,
        bool $checkfile,
        ?string $expectedcontent
    ): void {
        // Add temporary directory to permitted directories.
        set_config('permitted_dirs', $this->tempdir, 'tool_dataflows');

        // Replace placeholder paths with actual temp paths.
        if ($source === 'local_file') {
            $source = $this->tempdir . '/source.txt';
            // Create a test source file.
            file_put_contents($source, 'Test content');
        }
        if ($target === 'local_file') {
            $target = $this->tempdir . '/target.txt';
        }

        $dataflow = new dataflow();
        $dataflow->enabled = true;
        $dataflow->name = 'dataflow';
        $dataflow->save();
        $step = new step();
        $step->name = 'name';
        $step->type = connector_adl::class;
        $step->config = Yaml::dump([
            'accountname' => 'teststorage',
            'accesskey' => 'SOMEKEY',
            'source' => $source,
            'target' => $target,
        ]);
        $dataflow->add_step($step);

        ob_start();
        $engine = new engine($dataflow, $isdryrun, false);
        $connector = new connector_adl($step, $engine);
        $reflection = new \ReflectionClass($connector);

        $httpclientprop = $reflection->getProperty('httpclient');
        $httpclientprop->setAccessible(true);
        $httpclientprop->setValue($connector, $this->httpclient);

        // Queue mock responses.
        foreach ($responses as $response) {
            $this->mockhandler->append($response);
        }

        // Execute the method.
        $input = ['test' => 'data'];
        $result = $connector->execute($input);
        $output = ob_get_clean();

        // Assert that the input is returned unchanged.
        $this->assertEquals($input, $result);

        if ($isdryrun) {
            // Verify that the log message about skipping the operation was generated.
            $this->assertStringContainsString("Skipping copy to '/container/target.txt' as this is a dry run", $output);
        }

        if ($expectexception) {
            // Verify that the error was logged.
            $this->assertStringContainsString("ADL copy failed", $output);
        }

        if ($checkfile) {
            // Verify the file was created with the correct content.
            $this->assertFileExists($target);
            $this->assertEquals($expectedcontent, file_get_contents($target));
        }
    }
}
