<?php

$loader = require './vendor/autoload.php';
$loader->setUseIncludePath(true);

declare(ticks=1);
Firehed\ProcessControl\Daemon::run();

function my_reverse_function($job) {
	return strrev($job->workload());
}

$pm = new GearmanProcessManager;

// Example GearmanProcessManager class jobs
$pm->addFunction("my_reverse_function", "my_reverse_function");

$pm->addFunction("my_uppercase", function(GearmanJob $job) {
	return strtoupper($job->workload());
});

// ProcessManager requirement
$pm->start();


// Client: $client->doBackground('my_uppercase', $some_string_to_uppercase);