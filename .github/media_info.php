<?php

// --- Configuration ---
// !! VERIFY THIS PATH !! Use `which ffprobe` or `command -v ffprobe` via SSH.
define('FFPROBE_PATH', '/bin/ffprobe'); // Adjust if your ffprobe is elsewhere
// Directory for temporary downloaded files, relative to this script
define('TEMP_DIR_INFO', __DIR__ . '/temp_media_files_info'); // Separate temp for this script
// Set higher limits for potentially long analysis (especially if downloading large files first)
define('MAX_EXECUTION_TIME_INFO', 300); // 5 minutes
define('MEMORY_LIMIT_INFO', '256M');   // Usually less memory needed than merging
// --- End Configuration ---

// --- Global Variable for Log Path ---
$globalLogFilePathInfo = null;

// --- Helper Functions (some can be shared if this were one big file) ---

/**
 * Writes a message to the request-specific log file or falls back to PHP error_log.
 * (Slightly adapted for this script's log path variable)
 */
function writeToLogInfo($message) {
    global $globalLogFilePathInfo;
    $targetLogPath = $globalLogFilePathInfo;

    if ($targetLogPath) {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname($targetLogPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        file_put_contents($targetLogPath, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log("MediaInfo Script (Log Path Not Set): " . $message);
    }
}

/**
 * Centralized error handler: Logs details, sends JSON response, exits.
 * (Slightly adapted for this script's log function)
 */
function handleErrorAndLogInfo($errorMessage, $httpCode, $logContext = null) {
    global $globalLogFilePathInfo;
    $logMessage = "Error (HTTP $httpCode): $errorMessage";

    if ($logContext) {
         $contextStr = is_string($logContext) ? $logContext : print_r($logContext, true);
         if (strlen($contextStr) > 2048) {
              $contextStr = substr($contextStr, 0, 2048) . "... (truncated)";
         }
        $logMessage .= "\nContext/Details:\n" . $contextStr;
    }

    writeToLogInfo($logMessage); // Use this script's log function

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($httpCode);
    echo json_encode(["error" => $errorMessage]);
    exit;
}

/**
 * Executes an FFprobe command.
 */
function executeProbeCommand($command) {
    $ffprobePath = FFPROBE_PATH;
    // Ensure the command string passed doesn't start with the path already
    if (strpos($command, $ffprobePath) === 0) {
        $fullCommand = $command;
    } else {
        $fullCommand = $ffprobePath . ' ' . $command;
    }

    writeToLogInfo("Executing FFprobe: " . $fullCommand);

    $output_lines = [];
    $return_var = -1;

    exec($fullCommand . ' 2>&1', $output_lines, $return_var);
    $full_output = implode("\n", $output_lines);

    if ($return_var !== 0) {
        writeToLogInfo("FFprobe Execution Failed (Return Code: $return_var). Full Output:\n" . $full_output);
        $error_message = "FFprobe failed with exit code $return_var.";
        if ($return_var === 127 || stripos($full_output, 'No such file or directory') !== false || stripos($full_output, 'not found') !== false ) {
            if (stripos($full_output, $ffprobePath) !== false) {
                 $error_message = "FFprobe execution failed: Command not found. Path used: '$ffprobePath'.";
            }
        }
        // Try to get a more specific error from output
        foreach ($output_lines as $line) {
            if (preg_match('/(error|fail|invalid|corrupt|no such file|unable to open)/i', trim($line))) {
                $error_message .= " Potential cause: " . trim($line);
                break;
            }
        }
        if(count($output_lines) > 0) {
            $error_message .= " Last line of output: " . trim(end($output_lines));
        }
        return ["error" => $error_message, "raw_output" => $full_output];
    }
    return ["output" => $full_output, "error" => null]; // Return full output on success for JSON parsing
}


/**
 * Downloads a file from a URL using cURL. Returns temp path or throws Exception.
 * (Slightly adapted for this script's needs)
 */
function downloadFileForInfo($url, &$tempFiles) {
    if (empty($url)) {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("Invalid URL provided: $url");
    }

    writeToLogInfo("Downloading media file from $url");
    $parsedUrl = parse_url($url);
    $pathInfo = pathinfo($parsedUrl['path'] ?? '');
    $ext = strtolower($pathInfo['extension'] ?? 'tmp'); // Keep original extension or use tmp

    $tempFilename = generateFilenameBaseInfo('media') . '.' . $ext;
    $tempPath = TEMP_DIR_INFO . '/' . $tempFilename;

    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new Exception("Failed to open temporary file for writing: $tempPath");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240); // 4 mins for download
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $downloadedSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T);

    curl_close($ch);
    fclose($fp);

    $actualFileSize = file_exists($tempPath) ? filesize($tempPath) : -1;

    if (!empty($curlError) || $httpCode >= 400 || $actualFileSize <= 0) {
        $sizeInfo = "(Disk: $actualFileSize / Header: $downloadedSize)";
        @unlink($tempPath);
        throw new Exception("Failed to download media file from: $url (HTTP: $httpCode, Size: $sizeInfo, Error: $curlError)");
    }

    $tempFiles[] = $tempPath;
    writeToLogInfo("Media file download complete: $tempPath");
    return $tempPath;
}

/**
 * Deletes temporary files.
 */
function cleanupTempFilesInfo(array $files) {
    $filesToClean = array_filter($files);
    if(empty($filesToClean)) return;

    writeToLogInfo("Cleaning up temp files: " . implode(', ', $filesToClean));
    foreach ($filesToClean as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

/**
 * Generates a unique filename base.
 */
function generateFilenameBaseInfo($prefix = 'file') {
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
    return $safePrefix . '_' . time() . '_' . bin2hex(random_bytes(4));
}

// --- Script Execution Starts ---

$scriptNameInfo = basename($_SERVER['PHP_SELF']);
$scriptDirPathInfo = dirname($_SERVER['SCRIPT_NAME']);
$scriptDirPathInfo = ($scriptDirPathInfo == '/' || $scriptDirPathInfo == '\\') ? '' : $scriptDirPathInfo;
$protocolInfo = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http");
$hostInfo = $_SERVER['HTTP_HOST'];
$serverUrlInfo = $protocolInfo . "://" . $hostInfo . $scriptDirPathInfo . "/{$scriptNameInfo}";
$basePublicUrlInfo = $protocolInfo . "://" . $hostInfo . $scriptDirPathInfo;


// --- Handle GET Request (API Documentation) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');

    // --- Prerequisite Checks ---
    $ffprobeInstalled = 'Checking...'; $curlInstalledInfo = 'Checking...'; $permissionsOkInfo = false; $permissionsInfo = [];

    $ffprobeVersionOutput = []; $ffprobeReturnCode = -1;
    @exec(FFPROBE_PATH . ' -version 2>&1', $ffprobeVersionOutput, $ffprobeReturnCode);
    $ffprobeVersionOutput = implode("\n", $ffprobeVersionOutput);

    if ($ffprobeReturnCode === 0 && stripos($ffprobeVersionOutput, 'ffprobe version') !== false) { $ffprobeInstalled = 'Installed ✅'; }
    elseif ($ffprobeReturnCode === 127 || stripos($ffprobeVersionOutput, 'No such file') !== false || stripos($ffprobeVersionOutput, 'not found') !== false ) { $ffprobeInstalled = 'Not Found ❌ (Path: `' . FFPROBE_PATH . '` incorrect, or FFprobe not installed/accessible to web user).'; }
    else { if ($ffprobeReturnCode === -1 && empty($ffprobeVersionOutput)) { $ffprobeInstalled = 'Unknown Status ❓ (`exec` might be disabled in php.ini `disable_functions`).'; }
           else { $ffprobeInstalled = 'Unknown Status ❓ (Command failed or output unexpected. RC: ' . $ffprobeReturnCode . ', Output: ' . htmlspecialchars(substr($ffprobeVersionOutput,0,100)).'...)'; } }

    $curlInstalledInfo = function_exists('curl_version') ? 'Installed ✅' : 'Not Installed ❌ (Required for downloads)';
    $tempDirCheckInfo = TEMP_DIR_INFO;
    if (!is_dir($tempDirCheckInfo)) @mkdir($tempDirCheckInfo, 0775, true);
    $tempDirWritableInfo = is_writable($tempDirCheckInfo);
    $permissionsInfo[] = 'Temp Dir (' . basename(TEMP_DIR_INFO) . '): ' . ($tempDirWritableInfo ? 'Writable ✅' : 'Not Writable ❌');
    $permissionsOkInfo = $tempDirWritableInfo;

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Media File Information API</title>
        <link href='https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap' rel='stylesheet'>
        <style>
            body { font-family: 'Roboto', sans-serif; background-color: #f3f4f6; margin: 0; padding: 0; color: #333; line-height: 1.6; }
            h1 { background-color: #10b981; color: white; padding: 20px; text-align: center; margin: 0; font-weight: 500; }
            div.container { padding: 20px 30px 40px 30px; margin: 30px auto; max-width: 900px; background: white; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
            h2 { border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; margin-top: 30px; color: #059669; font-weight: 500;}
            code, pre { background-color: #f0fdf4; padding: 12px 15px; border: 1px solid #d1fae5; border-radius: 5px; display: block; margin-bottom: 15px; white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; font-size: 0.9em; color: #065f46; }
            ul { list-style-type: disc; margin-left: 20px; padding-left: 5px;}
            li { margin-bottom: 10px; }
            strong { color: #047857; font-weight: 500; }
            button { padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px; font-size: 0.95em; }
            button:hover { background-color: #059669; }
            .note { background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 15px; margin: 20px 0; border-radius: 4px;}
            .error { color: #dc2626; font-weight: bold; }
            .success { color: #059669; font-weight: bold; }
            .config-path { font-style: italic; color: #555; background-color: #e5e7eb; padding: 2px 4px; border-radius: 3px;}
            .status-list li { margin-bottom: 5px; list-style-type: none;}
            .status-icon { margin-right: 8px; display: inline-block; width: 20px; text-align: center;}
            .attribution a { color: #10b981; text-decoration: none; }
            .attribution a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <h1>Media File Information API</h1>
        <div class="container">
             <p style="text-align:center;">
                <img src="https://blog.automation-tribe.com/wp-content/uploads/2025/05/logo-automation-tribe-750.webp" alt="Automation Tribe Logo" style="max-width: 200px; margin-bottom: 10px;">
            </p>
            <p class="attribution" style="text-align:center; font-size: 0.9em; margin-bottom: 25px;">
                This API endpoint was made by <a href="https://www.automation-tribe.com" target="_blank" rel="noopener noreferrer">Automation Tribe</a>.<br>
                Join our community at <a href="https://www.skool.com/automation-tribe" target="_blank" rel="noopener noreferrer">https://www.skool.com/automation-tribe</a>.
            </p>

            <p>This API retrieves detailed information about a media file (audio or video) provided via a URL. Input is a <strong>JSON payload</strong>.</p>
            <p class="note"><strong>Logging:</strong> On processing errors, a log file (e.g., <code>media_info_timestamp.log</code>) will be created in the script's configured temporary directory (<code><?php echo htmlspecialchars(basename(TEMP_DIR_INFO)); ?></code>) inside a 'logs' subfolder. **Check this log for full FFprobe error details!**</p>

            <h2>Server Status</h2>
            <ul class="status-list">
                <li><span class="status-icon">🔧</span><strong>FFprobe Path Configured:</strong> <code class="config-path"><?php echo htmlspecialchars(FFPROBE_PATH); ?></code></li>
                <li><span class="<?php echo strpos($ffprobeInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($ffprobeInstalled, '✅') !== false ? '✅' : '❓'; ?></span><strong>FFprobe Status:</strong> <?php echo $ffprobeInstalled; ?></span></li>
                <li><span class="<?php echo strpos($curlInstalledInfo, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($curlInstalledInfo, '✅') !== false ? '✅' : '❌'; ?></span><strong>PHP cURL Extension:</strong> <?php echo $curlInstalledInfo; ?></span></li>
                <li><span class="status-icon">📁</span><strong>Folder Permissions:</strong>
                    <ul style="margin-left: 10px; margin-top: 5px;">
                        <?php foreach ($permissionsInfo as $perm): ?>
                        <li><span class="<?php echo strpos($perm, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($perm, '✅') !== false ? '✅' : '❌'; ?></span><?php echo $perm; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>
             <?php if (strpos($ffprobeInstalled, '❌') !== false || strpos($ffprobeInstalled, '❓') !== false || strpos($curlInstalledInfo, '❌') !== false || !$permissionsOkInfo): ?>
                <p class="error note"><strong>Action Required:</strong> Please address the items marked with ❌ or ❓ above. Ensure FFprobe is installed & accessible, PHP cURL is enabled, and Temp directory is writable. Check PHP `disable_functions` if FFprobe status is unknown. Consult server logs for details.</p>
            <?php endif; ?>

            <h2>API Usage</h2>
            <h3>Endpoint</h3>
            <code><?php echo htmlspecialchars($serverUrlInfo); ?></code>

            <h3>HTTP Method</h3>
            <code>POST</code>

            <h3>Headers</h3>
            <code>Content-Type: application/json</code>

            <h3>Request Body (JSON Payload)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["media_url" => "https://example.com/path/to/your/media.mp4_or_audio.mp3"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
            <ul>
                <li><code>media_url</code> (<strong>Required</strong>): URL to the audio or video file.</li>
            </ul>

            <h3>Success Response (JSON Example for a Video File)</h3>
            <?php
            $exampleSuccessResponse = [
                "filename" => "media_temp_name.mp4",
                "size_bytes" => 1234567,
                "duration_seconds" => 120.53,
                "format_name" => "mov,mp4,m4a,3gp,3g2,mj2",
                "format_long_name" => "QuickTime / MOV",
                "bit_rate_bps" => 819200,
                "video_stream" => [
                    "codec_name" => "h264",
                    "codec_long_name" => "H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10",
                    "profile" => "High",
                    "width" => 1920,
                    "height" => 1080,
                    "display_aspect_ratio" => "16:9",
                    "frame_rate" => "29.97", // or "30/1"
                    "bit_rate_bps" => 750000, // stream specific
                    "pixel_format" => "yuv420p"
                ],
                "audio_stream" => [
                    "codec_name" => "aac",
                    "codec_long_name" => "AAC (Advanced Audio Coding)",
                    "sample_rate_hz" => 48000,
                    "channels" => 2,
                    "channel_layout" => "stereo",
                    "bit_rate_bps" => 128000 // stream specific
                ],
                "raw_ffprobe_output" => "{... full JSON from ffprobe ...}" // Optional: for debugging
            ];
            ?>
            <pre><code><?php echo htmlspecialchars(json_encode($exampleSuccessResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
             <p><em>Note: <code>video_stream</code> or <code>audio_stream</code> will be <code>null</code> if the respective stream is not present in the file. <code>raw_ffprobe_output</code> can be very large.</em></p>

            <h3>Error Response (JSON)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["error" => "Concise error message."], JSON_PRETTY_PRINT)); ?></code></pre>
            <p>If an error occurs, check the <code>.log</code> file in the temp directory for details.</p>

            <?php
            $exampleCurlPayload = ["media_url" => "https://www.w3schools.com/html/mov_bbb.mp4"]; // A known public test video
            $escapedJsonPayloadInfo = escapeshellarg(json_encode($exampleCurlPayload, JSON_UNESCAPED_SLASHES));
            $curlCommandInfo = "curl -X POST " . escapeshellarg($serverUrlInfo) . " \\\n";
            $curlCommandInfo .= "  -H \"Content-Type: application/json\" \\\n";
            $curlCommandInfo .= "  -d $escapedJsonPayloadInfo";
            ?>
            <h2>How to Use (cURL Example)</h2>
            <p>Replace the example <code>media_url</code> and run in your terminal.</p>
            <pre id='curl-command-info'><?php echo htmlspecialchars($curlCommandInfo); ?></pre>
            <button onclick="navigator.clipboard.writeText(document.getElementById('curl-command-info').innerText.replace(/\\\n/g, '')); alert('cURL command copied (single line format)!');">Copy cURL Command</button>

            <h2>Important Notes</h2>
            <ul>
                <li><strong>FFprobe Path:</strong> Ensure <code><?php echo htmlspecialchars(FFPROBE_PATH); ?></code> is correct.</li>
                <li><strong>Download Time:</strong> The script downloads the entire file before analysis. Large files will take time.</li>
                <li><strong>PHP Limits:</strong> Check <code>max_execution_time</code> (<?php echo MAX_EXECUTION_TIME_INFO; ?>s) and <code>memory_limit</code> (<?php echo MEMORY_LIMIT_INFO; ?>).</li>
            </ul>
            <button onclick="location.reload()">Refresh Status</button>
        </div>
    </body>
    </html>
    <?php
    exit;
}


// --- Handle POST Request (Get Media Info) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(MAX_EXECUTION_TIME_INFO);
    ini_set('memory_limit', MEMORY_LIMIT_INFO);
    header('Content-Type: application/json; charset=utf-8');

    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    // --- Determine Log File Path EARLY ---
    // Create a 'logs' subdirectory within TEMP_DIR_INFO for tidiness
    $logDirForInfo = TEMP_DIR_INFO . '/logs';
    if (!is_dir($logDirForInfo)) {
        @mkdir($logDirForInfo, 0775, true);
    }
    $baseLogName = 'media_info_' . time();
    $globalLogFilePathInfo = $logDirForInfo . '/' . $baseLogName . '.log';

    if (json_last_error() !== JSON_ERROR_NONE) {
        handleErrorAndLogInfo("Invalid JSON received: " . json_last_error_msg(), 400, $jsonInput);
    }

    $mediaUrl = $data['media_url'] ?? null;
    if (empty($mediaUrl) || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        handleErrorAndLogInfo("JSON key 'media_url' (valid URL) is required.", 400, $data);
    }

    writeToLogInfo("Starting media info request for URL: " . $mediaUrl);

    // --- Ensure Temp Directory Exists and Is Writable ---
    if (!is_dir(TEMP_DIR_INFO)) {
        if (!@mkdir(TEMP_DIR_INFO, 0775, true) && !is_dir(TEMP_DIR_INFO)) {
            handleErrorAndLogInfo("Failed to create temp directory: ".TEMP_DIR_INFO, 500);
        }
    }
    if (!is_writable(TEMP_DIR_INFO)) {
        handleErrorAndLogInfo("Temp directory not writable: " . TEMP_DIR_INFO, 500);
    }

    $tempFilesInfo = [];
    $downloadedMediaFile = null;

    try {
        writeToLogInfo("Downloading media file...");
        $downloadedMediaFile = downloadFileForInfo($mediaUrl, $tempFilesInfo);
        if (!$downloadedMediaFile || !file_exists($downloadedMediaFile)) {
            throw new Exception("Media file download failed or file not found after download.");
        }
        writeToLogInfo("Media file downloaded to: " . $downloadedMediaFile);

        // --- FFprobe Analysis ---
        $ffprobeCommand = "-v quiet -print_format json -show_format -show_streams " . escapeshellarg($downloadedMediaFile);
        $probeResult = executeProbeCommand($ffprobeCommand);

        if ($probeResult['error'] !== null) {
            throw new Exception("FFprobe analysis failed: " . $probeResult['error']);
        }

        $probeData = json_decode($probeResult['output'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse FFprobe JSON output: " . json_last_error_msg() . ". Raw output: " . substr($probeResult['output'], 0, 500));
        }

        // --- Extract Information ---
        $info = [
            "filename" => basename($downloadedMediaFile),
            "size_bytes" => null,
            "duration_seconds" => null,
            "format_name" => null,
            "format_long_name" => null,
            "bit_rate_bps" => null,
            "video_stream" => null,
            "audio_stream" => null,
            // "raw_ffprobe_output" => $probeData // Uncomment if you want to include full raw output
        ];

        if (isset($probeData['format'])) {
            $format = $probeData['format'];
            $info['size_bytes'] = isset($format['size']) ? intval($format['size']) : null;
            $info['duration_seconds'] = isset($format['duration']) ? floatval($format['duration']) : null;
            $info['format_name'] = $format['format_name'] ?? null;
            $info['format_long_name'] = $format['format_long_name'] ?? null;
            $info['bit_rate_bps'] = isset($format['bit_rate']) ? intval($format['bit_rate']) : null;
        }

        if (isset($probeData['streams']) && is_array($probeData['streams'])) {
            foreach ($probeData['streams'] as $stream) {
                if ($info['video_stream'] === null && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                    $info['video_stream'] = [
                        "codec_name" => $stream['codec_name'] ?? null,
                        "codec_long_name" => $stream['codec_long_name'] ?? null,
                        "profile" => $stream['profile'] ?? null,
                        "width" => isset($stream['width']) ? intval($stream['width']) : null,
                        "height" => isset($stream['height']) ? intval($stream['height']) : null,
                        "display_aspect_ratio" => $stream['display_aspect_ratio'] ?? null,
                        "frame_rate" => $stream['r_frame_rate'] ?? ($stream['avg_frame_rate'] ?? null), // r_frame_rate is often preferred
                        "bit_rate_bps" => isset($stream['bit_rate']) ? intval($stream['bit_rate']) : null,
                        "pixel_format" => $stream['pix_fmt'] ?? null,
                        "tags" => $stream['tags'] ?? null, // e.g. rotation
                    ];
                } elseif ($info['audio_stream'] === null && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                    $info['audio_stream'] = [
                        "codec_name" => $stream['codec_name'] ?? null,
                        "codec_long_name" => $stream['codec_long_name'] ?? null,
                        "sample_rate_hz" => isset($stream['sample_rate']) ? intval($stream['sample_rate']) : null,
                        "channels" => isset($stream['channels']) ? intval($stream['channels']) : null,
                        "channel_layout" => $stream['channel_layout'] ?? null,
                        "bit_rate_bps" => isset($stream['bit_rate']) ? intval($stream['bit_rate']) : null,
                         "tags" => $stream['tags'] ?? null,
                    ];
                }
            }
        }

        writeToLogInfo("Media information extracted successfully.");
        http_response_code(200);
        echo json_encode($info);

    } catch (Exception $e) {
        handleErrorAndLogInfo($e->getMessage(), 500, "Media info processing failed.");
    } finally {
        cleanupTempFilesInfo($tempFilesInfo);
    }
    exit;
} else {
    handleErrorAndLogInfo("Method not allowed. Use GET for documentation or POST with JSON body.", 405);
}
?>