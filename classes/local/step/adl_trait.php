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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use tool_dataflows\helper;

/**
 * Azure data lake blob copy file step trait
 *
 * @package    tool_dataflows
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait adl_trait {
    /** @var string x-ms-version */
    public static string $xmsversion = '2025-01-05';

    /** @var string Storage account URL */
    private string $accounturl;

    /** @var string Storage account name */
    private string $accountname;

    /** @var string Storage account access key */
    private string $accesskey;

    /** @var Client HTTP client for making requests */
    private Client $httpclient;

    /**
     * Determines if this step has side effects
     *
     * It is considered to have a side effect if the target is outside the scratch directory.
     *
     * @return bool True if the step has side effects, false otherwise
     */
    public function has_side_effect(): bool {
        if (isset($this->stepdef)) {
            $config = $this->get_variables()->get('config');
            return !helper::path_is_relative($config->target);
        }

        return true;
    }

    /**
     * Defines the required form fields for this step
     *
     * Required fields:
     * - accountname: The Azure Storage account name
     * - accesskey: The access key for authentication
     * - source: The source path to copy from
     * - target: The target path to copy to
     *
     * @return array Array of field definitions
     */
    public static function form_define_fields(): array {
        return [
            'accountname' => ['type' => PARAM_TEXT, 'required' => true],
            'accesskey' => ['type' => PARAM_TEXT, 'required' => true, 'secret' => true],
            'source' => ['type' => PARAM_TEXT, 'required' => true],
            'target' => ['type' => PARAM_TEXT, 'required' => true],
        ];
    }

    /**
     * Allows each step type to determine a list of optional/required form
     * inputs for their configuration
     *
     * @param \MoodleQuickForm $mform
     * @throws \coding_exception
     */
    public function form_add_custom_inputs(\MoodleQuickForm &$mform) {
        // Storage account name.
        $mform->addElement('text', 'config_accountname', get_string('connector_adl:accountname', 'tool_dataflows'));

        // Access key.
        $mform->addElement('passwordunmask', 'config_accesskey', get_string('connector_adl:accesskey', 'tool_dataflows'));

        // Source path and help.
        $mform->addElement('text', 'config_source', get_string('connector_adl:source', 'tool_dataflows'), ['size' => '50']);
        $mform->addElement('static', 'config_json_path_help', '', get_string('connector_adl:source_help', 'tool_dataflows').
            \html_writer::nonempty_tag('pre', get_string('connector_adl:path_example', 'tool_dataflows').
                get_string('path_help_examples', 'tool_dataflows')));

        // Target path and help.
        $mform->addElement('text', 'config_target', get_string('connector_adl:target', 'tool_dataflows'), ['size' => '50']);
        $mform->addElement('static', 'config_json_path_help', '', get_string('connector_adl:target_help', 'tool_dataflows').
            \html_writer::nonempty_tag('pre', get_string('connector_adl:path_example', 'tool_dataflows').
                get_string('path_help_examples', 'tool_dataflows')));
    }

    /**
     * Parse query string into canonicalized format for Azure Storage API authentication
     *
     * @param string $path The path to canonicalize
     * @param string $querystring Optional query string to include in canonicalization
     * @return string The canonicalized resource string for use in authentication
     */
    private function get_canonicalized_resource(string $path, string $querystring = ''): string {
        $canonicalizedresource = "/$this->accountname$path";

        if (empty($querystring)) {
            return $canonicalizedresource;
        }

        // Parse query string into array.
        parse_str($querystring, $params);

        // Sort parameters by key.
        ksort($params);

        // Build canonicalized resource string.
        $canonicalizedparams = [];
        foreach ($params as $key => $value) {
            $canonicalizedparams[] = "$key:$value";
        }

        return $canonicalizedresource . "\n" . implode("\n", $canonicalizedparams);
    }

    /**
     * Generate authorization header for Azure Storage REST API
     *
     * @param string $method HTTP method (GET, PUT, etc.)
     * @param string $contentlength Content length of the request
     * @param string $contenttype Content type of the request
     * @param string $date Request date in GMT format
     * @param string $path Resource path
     * @param string $querystring Optional query string parameters
     * @param array $additionalheaders Optional additional headers to include in canonicalization
     * @return string Authorization header value for Azure Storage API request
     */
    private function generate_auth_header(
        string $method,
        string $contentlength,
        string $contenttype,
        string $date,
        string $path,
        string $querystring = '',
        array $additionalheaders = []): string {
        if ($contentlength === '0') {
            $contentlength = '';
        }

        // Sort additional headers by key.
        ksort($additionalheaders);

        // Canonicalized headers.
        $canonicalizedheaders = array_map(
            fn($key, $value) => strtolower($key) . ':' . trim($value),
            array_keys($additionalheaders),
            array_values($additionalheaders)
        );

        // Add required x-ms headers.
        $canonicalizedheaders[] = "x-ms-date:$date";
        $canonicalizedheaders[] = "x-ms-version:" . self::$xmsversion;
        sort($canonicalizedheaders);

        // Build string to sign according to Azure specs.
        $stringtosign = implode("\n", [
            strtoupper($method),                    // VERB.
            "",                                     // Content-Encoding.
            "",                                     // Content-Language.
            $contentlength,                         // Content-Length.
            "",                                     // Content-MD5.
            $contenttype,                           // Content-Type.
            "",                                     // Date (empty because we use x-ms-date).
            "",                                     // If-Modified-Since.
            "",                                     // If-Match.
            "",                                     // If-None-Match.
            "",                                     // If-Unmodified-Since.
            "",                                     // Range.
            implode("\n", $canonicalizedheaders),   // Canonicalized headers.
            $this->get_canonicalized_resource($path, $querystring),  // Canonicalized resource.
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringtosign, base64_decode($this->accesskey), true));
        return "SharedKey $this->accountname:$signature";
    }

    /**
     * Copy a blob within Azure Blob storage
     *
     * @param string $sourcepath Source blob path
     * @param string $destinationpath Destination blob path
     * @return bool True on successful copy
     * @throws \Exception|GuzzleException If the copy operation fails
     */
    public function copy_blob(string $sourcepath, string $destinationpath): bool {
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        try {
            // Construct full source URL.
            $sourceurl = "{$this->accounturl}{$sourcepath}";

            // Additional headers for copy operation.
            $additionalheaders = [
                'x-ms-copy-source' => $sourceurl,
            ];

            $this->httpclient->put("{$this->accounturl}{$destinationpath}", [
                'headers' => [
                    'x-ms-date' => $date,
                    'x-ms-version' => self::$xmsversion,
                    'x-ms-copy-source' => $sourceurl,
                    'Authorization' => $this->generate_auth_header(
                        'PUT',
                        '0',
                        '',
                        $date,
                        $destinationpath,
                        '',
                        $additionalheaders
                    ),
                ],
            ]);
            return true;
        } catch (\Exception $e) {
            throw new \Exception(
                get_string(
                    'adl_process_blob_failed',
                    'tool_dataflows',
                    ['action' => 'copy', 'error' => $e->getMessage()]
                )
            );
        }
    }

    /**
     * Upload a file to Azure Blob storage
     *
     * @param   string $path Name/path of the blob to create
     * @param   string $content Content to upload
     * @param   string $contenttype MIME type of the content (defaults to application/octet-stream)
     * @return  bool True on successful upload
     * @throws  \Exception|GuzzleException If the upload fails
     */
    public function upload_blob(string $path, string $content, string $contenttype = 'application/octet-stream'): bool {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentlength = strlen($content);

        try {
            $additionalheaders = [
                'x-ms-blob-type' => 'BlockBlob',
            ];

            $this->httpclient->put("{$this->accounturl}{$path}", [
                'headers' => [
                    'x-ms-date' => $date,
                    'x-ms-version' => self::$xmsversion,
                    'x-ms-blob-type' => 'BlockBlob',
                    'Content-Type' => $contenttype,
                    'Content-Length' => (string)$contentlength,
                    'Authorization' => $this->generate_auth_header(
                        'PUT',
                        (string)$contentlength,
                        $contenttype,
                        $date,
                        $path,
                        '',
                        $additionalheaders
                    ),
                ],
                'body' => $content,
            ]);
            return true;
        } catch (\Exception $e) {
            throw new \Exception(
                get_string(
                    'adl_process_blob_failed',
                    'tool_dataflows',
                    ['action' => 'upload', 'error' => $e->getMessage()]
                )
            );
        }
    }

    /**
     * Download a blob from Azure Blob storage
     *
     * @param   string $path path of the blob to download
     * @return  string|null Content of the blob, or null if not found
     * @throws  \Exception|GuzzleException If the download fails
     */
    public function download_blob(string $path): ?string {
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        try {
            $response = $this->httpclient->get("{$this->accounturl}{$path}", [
                'headers' => [
                    'x-ms-date' => $date,
                    'x-ms-version' => self::$xmsversion,
                    'Authorization' => $this->generate_auth_header('GET', '0', '', $date, $path),
                ],
            ]);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            throw new \Exception(
                get_string(
                    'adl_process_blob_failed',
                    'tool_dataflows',
                    ['action' => 'download', 'error' => $e->getMessage()]
                )
            );
        }
    }

    /**
     * Executes the file transfer operation based on source and target paths
     *
     * This method handles three different scenarios:
     * 1. Local to ADL: Uploads from local filesystem to Azure Data Lake
     * 2. ADL to Local: Downloads from Azure Data Lake to local filesystem
     * 3. ADL to ADL: Transfers between two Azure Data Lake locations
     *
     * ADL paths are identified by the 'adl://' prefix.
     *
     * @param mixed|null $input Optional input data (not used in this implementation)
     * @return mixed The input data
     * @throws GuzzleException
     */
    public function execute($input = null): mixed {
        // Initialize HTTP client if not already set.
        if (!isset($this->httpclient)) {
            $this->httpclient = new Client();
        }

        // Get step variables.
        $stepvars = $this->get_variables();
        $config = $stepvars->get('config');
        $this->accountname = $config->accountname;
        $this->accounturl = $this->get_storage_account_url($config->accountname);
        $this->accesskey = $stepvars->evaluate($this->stepdef->config->accesskey);

        // Check if source and target paths are ADL paths.
        $sourceisadl = $this->is_adl_path($config->source);
        $targetisadl = $this->is_adl_path($config->target);

        // Resolve the actual paths.
        $sourcepath = $this->resolve_path($config->source, $sourceisadl);
        $targetpath = $this->resolve_path($config->target, $targetisadl);

        // Do not execute ADL operations during a dry run.
        if ($this->enginestep->engine->isdryrun) {
            $this->enginestep->log("Skipping copy to '{$targetpath}' as this is a dry run.");
            return $input;
        }

        try {
            // Determine the operation type based on source and target.
            if (!$sourceisadl && $targetisadl) {
                // Local to ADL.
                $content = file_get_contents($sourcepath);
                $mimetype = mime_content_type($sourcepath) ?: 'application/octet-stream';
                $this->upload_blob($targetpath, $content, $mimetype);
            } else if ($sourceisadl && !$targetisadl) {
                // ADL to Local.
                $content = $this->download_blob($sourcepath);
                file_put_contents($targetpath, $content);
            } else if ($sourceisadl && $targetisadl) {
                // ADL to ADL - Use server-side copy.
                $this->copy_blob($sourcepath, $targetpath);
            }
        } catch (\Exception $e) {
            $this->enginestep->log(get_string('adl_copy_failed', 'tool_dataflows', $e->getMessage()));
            return $input;
        }

        return $input;
    }

    /**
     * Returns whether the path is marked as being in Azure Data Lake
     *
     * @param   string $path Path to check
     * @return  bool Whether the path is an Azure Data Lake path (starts with 'adl://')
     */
    public function is_adl_path(string $path): bool {
        return str_starts_with($path, self::ADL_PREFIX);
    }

    /**
     * Resolves a path based on whether it's an ADL path or local path
     *
     * @param   string $path The path to resolve
     * @param   bool $isadl Whether the path is an ADL path
     * @return  string The resolved path - for ADL paths, removes the prefix; for local paths, uses engine resolver
     */
    public function resolve_path(string $path, bool $isadl): string {
        if ($isadl) {
            // ADL path: '/' + path without the 'adl://' prefix.
            return '/' . substr($path, strlen(self::ADL_PREFIX));
        }

        // Local/other path: resolved using the engine's resolve path method.
        return $this->enginestep->engine->resolve_path($path);
    }

    /**
     * Validate the configuration settings.
     *
     * @param object $config
     * @return true|array Returns true if the configuration is valid, or an array of errors otherwise
     * @throws \coding_exception
     */
    public function validate_config($config): bool|array {
        $errors = [];

        // Check mandatory fields.
        foreach (['accountname', 'accesskey', 'source', 'target'] as $field) {
            if (empty($config->$field)) {
                $errors["config_$field"] = get_string('config_field_missing', 'tool_dataflows', $field, true);
            }
        }

        if (!empty($config->source) && !empty($config->target)) {
            // Check if source is a directory when it's a local path.
            if (!$this->is_adl_path($config->source) && is_dir($config->source)) {
                $errors['config_source'] = get_string('connector_adl:source_is_a_directory', 'tool_dataflows', null, true);
            }

            // Ensure at least one path is ADL.
            if (!$this->is_adl_path($config->source) && !$this->is_adl_path($config->target)) {
                $errormsg = get_string('connector_adl:missing_adl_source_or_target', 'tool_dataflows', null, true);
                $errors['config_source'] = $errors['config_source'] ?? $errormsg;
                $errors['config_target'] = $errors['config_target'] ?? $errormsg;
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Perform any extra validation that is required only for runs.
     *
     * @return true|array Returns true if the configuration is valid, or an array of errors otherwise
     */
    public function validate_for_run(): bool|array {
        $config = $this->stepdef->config;
        $errors = [];

        foreach (['source', 'target'] as $field) {
            $error = helper::path_validate($config->$field);
            if ($error !== true) {
                $errors["config_$field"] = $error;
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Get the storage account URL
     *
     * @param string $accountname
     * @return string
     */
    private function get_storage_account_url(string $accountname): string {
        return 'https://' . $accountname . '.blob.core.windows.net';
    }
}
