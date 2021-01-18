<?php

use App\Api;
use App\PdoFactory;

require __DIR__ . '/../vendor/autoload.php';


$email = $_REQUEST['email'];
$secret = $_REQUEST['secret'];

$pdo = (new PdoFactory(Api::getDatabaseSettings()))->createPdo();
$setupService = new \App\SetupService($pdo);

try {
    $setupService->setupStep2($email, $secret);
} catch (\Exception $e) {
    header("HTTP/1.0 404 Not found");
    error_log(print_r( $e, true));
    die("Failure! Maybe this link has already been used?");
}
echo "Success! Please check your e-mail again!";

