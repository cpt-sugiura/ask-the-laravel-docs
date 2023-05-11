<?php

use CosmeDev\AskDocs\AskDocsReceiveQuestionFromPost;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$jsonData = json_decode(file_get_contents("php://input"), true);
if(isset($jsonData['message']) && is_string($jsonData['message'])) {
    $askDocs = new AskDocsReceiveQuestionFromPost();
    $askDocs->start($jsonData['message']);
}else {
    echo file_get_contents(__DIR__ . '/ask-docs.html');
}
