<?php

require __DIR__ . '/vendor/autoload.php';

use App\Support\DB;

// This is a dummy worker file.
// In a real application, this file would contain background tasks
// that are executed asynchronously, for example, using a message queue.

echo "Worker started...\n";

// Example: A dummy task that simulates some work
function dummyTask() {
    echo "Performing dummy task...\n";
    sleep(5); // Simulate a long-running task
    echo "Dummy task completed.\n";
}

// You could call your tasks here, or set up a listener for a queue
dummyTask();

echo "Worker finished.\n";

?>
