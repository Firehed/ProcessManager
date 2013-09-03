<?php

// This is an example of how to submit a job to Gearman for background 
// processing. It's not specific to GearmanWorkerExample, but it does use the 
// functions that example registers. Run this *after* starting the worker.

$client = new GearmanClient();
$client->addServer();

echo "Sending a 'flip_it' job to gearman...\n";
echo "Returned: ";
echo $client->doNormal("flip_it", "Hello World!");
echo "\n";

echo "Sending a 'my_uppercase' job to gearman...\n";
echo "Returned: ";
echo $client->doNormal('my_uppercase', 'some lowercase string');
echo "\n";

