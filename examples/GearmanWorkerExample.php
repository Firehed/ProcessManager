<?php

// Use Composer's autoloader
if (file_exists('../vendor/autoload.php')) {
	require_once '../vendor/autoload.php';
} elseif (file_exists('../../../vendor/autoload.php')) {
	require_once '../../../vendor/autoload.php';
}

// Wrapper for strrev(), invoked when a "flip_it" job is received (more below)
function my_reverse_function(GearmanJob $job) {
	return strrev($job->workload());
}

// Required example code: set up ticks (or process control signals can't 
// register)
declare(ticks=1);

// Optional but highly recommended: daemonize the worker manager
Firehed\ProcessControl\Daemon::run();

// Get a new worker manager for configuration
$pm = new Firehed\ProcessControl\GearmanWorkerManager;

// Example GearmanWorkerManager class jobs
//
// Param 1 is the job name (corresponds to param 1 of GearmanClient::doX class 
// of functions)
//
// Param 2 is the callable to invoke when such a job is received - anything 
// callable with "call_user_func" should work. The function must accept one 
// parameter: GearmanJob $job. This means native PHP funtions must be wrapped: 
// see 'my_reverse_function'
//

$pm->addFunction("flip_it", "my_reverse_function");

$pm->addFunction("my_uppercase", function(GearmanJob $job) {
	return strtoupper($job->workload());
});

// Optional: set number of workers
$pm->setWorkerCount(2);

// When other configuration options are available, examples will be added here

// ProcessManager requirement: start working
$pm->start();

