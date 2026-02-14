<?php
// debug_test.php
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$binPath = 'd:\inetpub\wwwroot\bin\yt-dlp.exe';
$downloadDir = 'd:\inetpub\wwwroot\downloads';

echo "PHP Version: " . phpversion() . "\n";
echo "Bin Path: $binPath\n";
echo "Bin Exists: " . (file_exists($binPath) ? 'YES' : 'NO') . "\n";
echo "Downloads Dir: $downloadDir\n";
echo "Downloads Writable: " . (is_writable($downloadDir) ? 'YES' : 'NO') . "\n";

if (!function_exists('exec')) {
    die("Error: exec() function is disabled.\n");
}

echo "Testing yt-dlp version...\n";
$output = [];
$return = 0;
// Force UTF-8 encoding for output
exec('chcp 65001 & "' . $binPath . '" --version', $output, $return);
echo "Return Code: $return\n";
echo "Output:\n" . implode("\n", $output) . "\n";

echo "\nTesting URL Info (Youtube)...\n";
$testUrl = "https://www.youtube.com/watch?v=BaW_jenozKc"; // Short video
$cmd = 'chcp 65001 & "' . $binPath . '" -J --flat-playlist ' . escapeshellarg($testUrl);
$output = [];
exec($cmd, $output, $return);
echo "Return Code: $return\n";
// echo "Full Output (first 500 chars): " . substr(implode("", $output), 0, 500) . "\n";

$json = implode("", $output);
$data = json_decode($json, true);
echo "JSON Decode Error: " . json_last_error_msg() . "\n";
if ($data) {
    echo "Title: " . ($data['title'] ?? 'N/A') . "\n";
    echo "Duration: " . ($data['duration'] ?? 'N/A') . "\n";
    echo "Thumbnail: " . ($data['thumbnail'] ?? 'N/A') . "\n";
} else {
    echo "Failed to decode JSON.\n";
}
?>
