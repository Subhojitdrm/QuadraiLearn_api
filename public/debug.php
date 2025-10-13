<?php
header('Content-Type: text/plain');
echo 'DOCUMENT_ROOT: ' . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo 'SCRIPT_FILENAME: ' . $_SERVER['SCRIPT_FILENAME'] . "\n";
echo 'REQUEST_URI: ' . $_SERVER['REQUEST_URI'] . "\n";
echo 'PHP_SELF: ' . $_SERVER['PHP_SELF'] . "\n";
