<?php

$loader = require './vendor/autoload.php';
$loader->setUseIncludePath(true);

declare(ticks=1);
Firehed\ProcessControl\Daemon::run();

// these functions are defined in the GearmanProcessWorker class
function my_reverse_function($job) {
	return strrev($job->workload());
}

function my_uppercase(GearmanJob $job) {
	return strtoupper($job->workload());
}

$pm = new GearmanProcessManager;
