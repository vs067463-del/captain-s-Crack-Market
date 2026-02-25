<?php
// api.php
// Ensure no output before JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding("UTF-8");

// Log to file only
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

set_time_limit(600);

function send_json($data)
{
    // Clear any previous buffer
    ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function send_error($msg, $details = null)
{
    send_json(['error' => $msg, 'details' => $details]);
}

try {
    $action = $_POST['action'] ?? '';
    $url = $_POST['url'] ?? '';

    if (!$url)
        send_error('No URL provided');

    $root = dirname(dirname(dirname(__FILE__)));
    $binPath = $root . '\bin\yt-dlp.exe';
    $ffmpegPath = $root . '\bin\ffmpeg.exe';

    // Helper to get physical root from fs.ashx
    function get_fs_physical_root()
    {
        // Try localhost loopback
        $url = "http://localhost/modules/file-manager/api/fs.ashx?action=ping";

        // Use stream context to force short timeout
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents($url, false, $ctx);

        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['root']) && is_dir($data['root'])) {
                return $data['root'];
            }
        }
        return false;
    }

    // Determine download directory
    $fsRoot = get_fs_physical_root();

    // Fallback if fs.ashx is unreachable:
    // Try standard locations (files folder relative to root)
    if (!$fsRoot) {
        // Default fallback: ../../../ftp if it exists, or just use a local 'downloads' folder
        $potential = $root . '\ftp';
        if (is_dir($potential)) {
            $fsRoot = $potential;
        } else {
            // Absolute worst case: use system temp or module dir (not recommended for persistence)
            $fsRoot = sys_get_temp_dir();
        }
    }

    $downloadDir = $fsRoot . '\VideoDownloads';

    if (!file_exists($binPath))
        send_error('yt-dlp binary not found', $binPath);

    // Ensure directory exists (recursive)
    if (!file_exists($downloadDir)) {
        if (!mkdir($downloadDir, 0777, true))
            send_error('Failed to create download directory', $downloadDir);
    }

    $hasFfmpeg = file_exists($ffmpegPath);

    // Function to run yt-dlp safely
    function run_yt_dlp_command($args)
    {
        global $binPath, $ffmpegPath, $hasFfmpeg;

        // Add ffmpeg location if exists
        if ($hasFfmpeg) {
            $args .= ' --ffmpeg-location "' . $ffmpegPath . '"';
        }

        // Force UTF-8 and no-playlist
        $cmd = 'chcp 65001 & "' . $binPath . '" --encoding utf-8 --no-check-certificate --no-playlist ' . $args;

        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $return_value = proc_close($process);

            return [
                'code' => $return_value,
                'stdout' => $stdout,
                'stderr' => $stderr
            ];
        }
        return ['code' => -1, 'stdout' => '', 'stderr' => 'Failed to spawn process'];
    }

    if ($action === 'info') {
        $result = run_yt_dlp_command('-J ' . escapeshellarg($url));

        if ($result['code'] !== 0) {
            send_error('Failed to fetch video info', $result['stderr']);
        }

        $jsonStr = $result['stdout'];
        $jsonStart = strpos($jsonStr, '{');
        if ($jsonStart !== false)
            $jsonStr = substr($jsonStr, $jsonStart);

        $data = json_decode($jsonStr, true);

        if (!$data)
            send_error('Invalid JSON from yt-dlp', substr($result['stdout'], 0, 500));

        send_json([
            'id' => $data['id'] ?? '',
            'title' => $data['title'] ?? 'Unknown Title',
            'thumbnail' => $data['thumbnail'] ?? '',
            'duration' => isset($data['duration']) ? gmdate("H:i:s", $data['duration']) : '??:??',
            'uploader' => $data['uploader'] ?? ''
        ]);

    }
    // === Action: Formats ===
    if ($action === 'formats') {
        // The run_yt_dlp_command function already adds --no-check-certificate and --no-playlist
        // Wait, NO IT DOES NOT. It adds --no-playlist inside run_yt_dlp_command!
        // Let's check run_yt_dlp_command definition again.
        // It says: $cmd = 'chcp 65001 & "' . $binPath . '" --encoding utf-8 --no-check-certificate --no-playlist ' . $args;
        // SO IT DOES ADD IT.
        // But maybe --dump-json on a playlist URL still behaves weirdly if it's a mix?
        // Or maybe args need to be carefully passed.
        // If I pass "--dump-json url", args becomes "--dump-json url".
        // Command: ... --no-playlist --dump-json url
        // This SHOULD work for single video.
        // But for a mix list, maybe it fails?
        // Let's try explicit --no-playlist AGAIN in args just in case, or maybe the issue is something else.
        // YouTube mix lists are tricky.
        // Error was "Failed to parse info JSON".
        // If output was empty or invalid.
        // Let's look at the error log if possible, but we can't.
        // If I add --no-playlist to args, it might double it up, but that's fine.

        $output = run_yt_dlp_command("--dump-json --no-playlist " . escapeshellarg($url));

        if ($output['code'] !== 0) {
            // Fallback: maybe it failed due to some transient issue, try flat-playlist
            $output = run_yt_dlp_command("--dump-json --flat-playlist " . escapeshellarg($url));
        }

        if ($output['code'] !== 0)
            send_error('Failed to fetch formats', $output['stderr']);

        $jsonStr = $output['stdout'];
        $jsonStart = strpos($jsonStr, '{');
        if ($jsonStart !== false) {
            $jsonStr = substr($jsonStr, $jsonStart);
        }

        $json = json_decode($jsonStr, true);
        if (!$json)
            send_error('Failed to parse info JSON');

        $formats = [];
        if (isset($json['formats'])) {
            foreach ($json['formats'] as $f) {
                // Filter out video-only or audio-only if you want, 
                // BUT for quality selection we usually want to see video-only options 
                // because yt-dlp will merge them with best audio automatically 
                // if we select them and have ffmpeg.

                // Let's simplified the list for the user
                $note = isset($f['format_note']) ? $f['format_note'] : '';
                $ext = isset($f['ext']) ? $f['ext'] : '';
                $res = isset($f['height']) ? $f['height'] . 'p' : '';
                $id = $f['format_id'];

                // Skip dashboard/m3u8 if usually not playable directly or handled automatically
                if (strpos($f['protocol'] ?? '', 'm3u8') !== false)
                    continue;

                // Helper label
                $label = "$res ($ext)";
                if ($note)
                    $label .= " - $note";
                if (isset($f['vcodec']) && $f['vcodec'] != 'none')
                    $label .= " [Video]";
                if (isset($f['acodec']) && $f['acodec'] != 'none')
                    $label .= " [Audio]";

                $formats[] = [
                    'id' => $id,
                    'label' => $label,
                    'ext' => $ext,
                    'res' => $f['height'] ?? 0,
                    'is_video' => (isset($f['vcodec']) && $f['vcodec'] != 'none')
                ];
            }
        }

        // Sort by resolution desc
        usort($formats, function ($a, $b) {
            return $b['res'] - $a['res'];
        });

        send_json([
            'title' => $json['title'] ?? 'Unknown',
            'duration' => $json['duration_string'] ?? '0:00',
            'thumbnail' => $json['thumbnail'] ?? '',
            'formats' => $formats
        ]);

    } elseif ($action === 'download') {
        $formatId = $_POST['format'] ?? '';
        $audioOnly = filter_var($_POST['audioOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Turn off buffering for streaming
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);
        for ($i = 0; $i < ob_get_level(); $i++) {
            ob_end_flush();
        }
        ob_implicit_flush(1);

        // We can't use send_json here because we are streaming.
        // We will output ND-JSON (Newline Delimited JSON).
        header('Content-Type: application/x-ndjson; charset=utf-8');

        function stream_msg($data)
        {
            echo json_encode($data) . "\n";
            @flush();
        }

        // Output template
        $extTemplate = $audioOnly ? 'mp3' : '%(ext)s';
        $outputTemplate = $downloadDir . '\%(title)s.%(ext)s';

        // Prepare command
        if ($audioOnly) {
            // Audio mode
            // -x --audio-format mp3
            // Note: format selection is usually ignored or should be bestaudio if we are extracting
            $format = "bestaudio/best";
            $args = "--newline -x --audio-format mp3 --audio-quality 0 -o \"" . $outputTemplate . "\" " . escapeshellarg($url);
        } else {
            // Video mode
            if ($formatId) {
                if ($hasFfmpeg) {
                    $format = "$formatId+bestaudio/best";
                } else {
                    $format = $formatId;
                }
            } else {
                if ($hasFfmpeg) {
                    $format = "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best";
                } else {
                    $format = "best[ext=mp4]/best";
                }
            }
            $args = "--newline -f " . escapeshellarg($format) . " -o \"" . $outputTemplate . "\" " . escapeshellarg($url);

            if ($hasFfmpeg) {
                $args .= " --merge-output-format mp4";
            }
        }

        $cmd = 'chcp 65001 & "' . $binPath . '" --encoding utf-8 --no-check-certificate --no-playlist ' . $args;
        if ($hasFfmpeg) {
            $cmd .= ' --ffmpeg-location "' . $ffmpegPath . '"';
        }

        // Start process
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        $filename = "";
        $isDownloading = false;

        if (is_resource($process)) {
            // Non-blocking read? No, PHP on Windows is tricky with non-blocking pipes.
            // We will read line by line. stream_get_line is good.

            // Send start event
            stream_msg(['status' => 'started', 'cmd' => 'Processing...']);

            while (!feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line === false)
                    break;

                $line = trim($line);
                if (!$line)
                    continue;

                // Parse progress
                // format: [download]  23.5% of 10.00MiB at 2.00MiB/s ETA 00:05
                if (strpos($line, '[download]') !== false) {
                    if (strpos($line, 'Destination:') !== false) {
                        $parts = explode('Destination:', $line);
                        if (count($parts) > 1)
                            $filename = trim($parts[1]);
                        stream_msg(['status' => 'info', 'filename' => basename($filename)]);
                    } elseif (strpos($line, 'has already been downloaded') !== false) {
                        $parts = explode('[download]', $line);
                        if (count($parts) > 1) {
                            $fpart = trim($parts[1]);
                            $filename = preg_replace('/ has already been downloaded.*/', '', $fpart);
                            stream_msg(['status' => 'info', 'filename' => basename($filename)]);
                        }
                    } elseif (preg_match('/(\d+(?:\.\d+)?)%/', $line, $matches)) {
                        $percent = floatval($matches[1]);
                        stream_msg(['status' => 'progress', 'percent' => $percent, 'line' => $line]);
                    }
                } elseif (strpos($line, '[ExtractAudio]') !== false) {
                    stream_msg(['status' => 'converting', 'msg' => 'Extracting Audio...']);
                } elseif (strpos($line, '[Merger]') !== false) {
                    stream_msg(['status' => 'converting', 'msg' => 'Merging formats...']);
                } elseif (strpos($line, 'ERROR:') !== false) {
                    stream_msg(['status' => 'error', 'msg' => $line]);
                }
            }

            // Read stderr for comprehensive error logging if needed
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $ret = proc_close($process);

            if ($ret === 0 || $filename) {
                // If audio extraction happened, filename might have changed extension
                if ($audioOnly) {
                    // The registered filename usually has the original ext (e.g. webm) in 'Destination:' 
                    // but -o was set.
                    // Actually, with -o template, Destination usually reflects target.
                    // But let's double check if .mp3 exists.
                    // If filename ended in .webm, replace with .mp3
                    $f_mp3 = preg_replace('/\.(webm|m4a|mp4)$/i', '.mp3', $filename);
                    if (file_exists($f_mp3))
                        $filename = $f_mp3;
                }

                stream_msg(['status' => 'finished', 'filename' => basename($filename), 'full_path' => $filename]);
            } else {
                stream_msg(['status' => 'error', 'msg' => 'Process exited with code ' . $ret, 'details' => $stderr]);
            }
        } else {
            stream_msg(['status' => 'error', 'msg' => 'Failed to start process']);
        }
        exit;

    } else {
        send_error('Invalid action');
    }

} catch (Throwable $e) {
    send_error('Server Exception', $e->getMessage());
}
?>