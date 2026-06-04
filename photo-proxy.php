<?php
header('Content-Type: application/javascript');
$url = 'https://script.google.com/macros/s/AKfycbyJgBxqksYcbPKnvCOMc7I1SZbgJbM-v6A1BaeSn0mr8aRRZMuSpJwuceOij4cAesct/exec';
$ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => true]]);
$data = @file_get_contents($url, false, $ctx);
echo $data ?: '_photosReady([])';
