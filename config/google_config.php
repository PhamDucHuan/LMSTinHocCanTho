<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env.php';

$clientID = envValue('GOOGLE_CLIENT_ID', '');
$clientSecret = envValue('GOOGLE_CLIENT_SECRET', '');
$redirectUri = envValue('GOOGLE_REDIRECT_URI', appUrl('includes/google_auth.php'));

if ($clientID === '' || $clientSecret === '') {
    throw new RuntimeException('Google OAuth chưa được cấu hình.');
}

$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope("email");
$client->addScope("profile");
$client->addScope(Google_Service_Drive::DRIVE_FILE);

return $client;
?>
