<?php
// Set the content type to JSON and the response code to 404
header('Content-Type: application/json');
http_response_code(404);

// Output a simple JSON error message
echo json_encode(['error' => 'Not Found']);