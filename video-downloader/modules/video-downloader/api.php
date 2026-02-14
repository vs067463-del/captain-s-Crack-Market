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
    // Use physical path E:\ftp as requested by user
    // Subfolder VideoDownloads to keep it clean
    $downloadDir = 'E:\ftp\VideoDownloads';

    if (!file_exists($binPath))
        send_error('yt-dlp binary not found', $binPath);

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
        $formatId = isset($_POST['format']) ? $_POST['format'] : '';

        // Output template
        $outputTemplate = $downloadDir . '\%(title)s.%(ext)s';

        // Log command
        // send_json(['log' => "Starting download for $url to $outputTemplate"]); 
        // We can't send json and then continue.

        // Prepare command
        // If formatId is provided, use it. But usually we want formatId+bestaudio
        // If formatId implies video-only, yt-dlp needs to merge.

        if ($formatId) {
            // Use specific format + best audio (if strictly video), or just the format
            // safest is "ID+bestaudio/best" if the ID is video-only.
            // But if ID is a pre-merged format (like 22), adding +bestaudio might fail or be redundant.
            // Actually, yt-dlp is smart. "ID+bestaudio/ID" works well.
            if ($hasFfmpeg) {
                $format = "$formatId+bestaudio/best";
            } else {
                $format = $formatId; // Can't merge without ffmpeg
            }
        } else {
            // Default behavior
            if ($hasFfmpeg) {
                $format = "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best";
            } else {
                $format = "best[ext=mp4]/best";
            }
        }

        // The run_yt_dlp_command function already adds --no-check-certificate and --no-playlist
        // NOTE: escapeshellarg on Windows replaces % with space! We must manually quote the template.
        $args = "--newline -f " . escapeshellarg($format) . " -o \"" . $outputTemplate . "\" " . escapeshellarg($url);

        // Force merge to mp4 if ffmpeg is present, finding best compatibility
        // This fixes the issue where selecting 'mp4' video + 'opus' audio results in MKV
        if ($hasFfmpeg) {
            $args .= " --merge-output-format mp4";
        }

        $cmd = 'chcp 65001 & "' . $binPath . '" --encoding utf-8 --no-check-certificate --no-playlist ' . $args;
        if ($hasFfmpeg) {
            $cmd .= ' --ffmpeg-location "' . $ffmpegPath . '"';
        }

        // Use proc_open to capture output in real-time? 
        // For now, let's just run it and return the result. 
        // If it takes too long, PHP might timeout. 
        // Ideally we should spawn a background process or stream output.
        // Given the constraints and previous impl, we run it blocking.

        // We need to capture filename. yt-dlp prints "[download] Destination: ..."
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        $stdout_content = "";
        $stderr_content = "";
        $filename = "";

        if (is_resource($process)) {
            // Read output
            while (!feof($pipes[1])) {
                $line = fgets($pipes[1]);
                $stdout_content .= $line;
                if (strpos($line, '[download] Destination:') !== false) {
                    // Extract filename
                    // Line looks like: [download] Destination: ...\VideoDownloads\MyVideo.mp4
                    $parts = explode('Destination:', $line);
                    if (count($parts) > 1) {
                        $filename = trim($parts[1]);
                    }
                }
                // Also check "has already been downloaded"
                if (strpos($line, 'has already been downloaded') !== false) {
                    $parts = explode('[download]', $line);
                    if (count($parts) > 1) {
                        $fpart = trim($parts[1]);
                        // Cleanup "has already..."
                        $filename = preg_replace('/ has already been downloaded.*/', '', $fpart);
                    }
                }
            }
            while (!feof($pipes[2])) {
                $stderr_content .= fgets($pipes[2]);
            }

            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $return_value = proc_close($process);

            if ($return_value === 0 || strpos($stdout_content, '100%') !== false) {
                if (!$filename) {
                    // Try to guess or find from stdout
                    // fallback
                    $filename = "Unknown (check folder)";
                }
                // Clean up filename path
                $filename = basename($filename);

                send_json(['status' => 'ok', 'filename' => $filename, 'log' => $stdout_content]);
            } else {
                send_error('Download failed', $stderr_content . "\n" . $stdout_content);
            }
        } else {
            send_error('Failed to start download process');
        }
    } else {
        send_error('Invalid action');
    }

} catch (Throwable $e) {
    send_error('Server Exception', $e->getMessage());
}
?>