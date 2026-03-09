<?php
declare(strict_types=1);

/**
 * ChileMon - AMI ping test directo
 */

require_once __DIR__ . '/../../../config/app.php';

header('Content-Type: application/json');

$fp = fsockopen(AMI_HOST, AMI_PORT, $errno, $errstr, 3);

if (!$fp) {
    echo json_encode([
        "success" => false,
        "error" => "socket failed: $errstr"
    ]);
    exit;
}

// leer banner
fgets($fp, 1024);

$login =
"Action: Login\r\n".
"Username: ".AMI_USER."\r\n".
"Secret: ".AMI_PASS."\r\n".
"Events: off\r\n\r\n";

fwrite($fp, $login);

// leer respuesta
$response = '';
while (!feof($fp)) {
    $line = fgets($fp, 1024);
    if ($line == "\r\n") break;
    $response .= $line;
}

fclose($fp);

echo json_encode([
    "success" => true,
    "raw_response" => $response
]);