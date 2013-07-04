<?php

include './Daemon.php';
declare(ticks=1);
Daemon::run();

$isParent = getmypid();
$pm = new GearmanProcessManager;

abstract class ProcessManager {

	private $managerPid;
	private $myPid;
	private $workerProcesses = [];
	private $shouldWork = true;

	function __construct() {
		$this->managerPid = $this->myPid = getmypid();
		$this->installSignals();
		$this->spawnWorkers();
		$this->manageWorkers();
	}

	private function manageWorkers() {
		while (1) {
			// Do nothing other than wait for SIGTERM/SIGIN
			sleep(5);
		}
	}

	private function installSignals() {
		$this->logDebug("$this->myPid SIGTERM handler installation");
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
	}

	function signal($signo) {
		if ($this->isParent()) {
			$this->logInfo('Parent got sigterm');
			$this->logDebug("Children: " . print_r($this->workerProcesses, true));
			$this->stop_children(SIGTERM);
			while ($this->workerProcesses) {
				$status = null;
				if ($exited = pcntl_wait($status, WNOHANG)) {
					unset($this->workerProcesses[$exited]);
					$this->logDebug("Worker $exited got WNOHANG");
				}
				else {
					sleep(1);
				}
			}
			$this->logInfo("Parent shutting down");
			exit;
		}
		else {
			$this->logInfo("Child $this->myPid received SIGTERM; stopping work");
			$this->shouldWork = false;
		}
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
	}

	function stop_children($sig = SIGTERM) {
		foreach ($this->workerProcesses as $pid) {
			$this->logDebug("Sending SIGTERM to $pid");
			posix_kill($pid, $sig);
			if (!posix_kill($pid, 0)) {
				$this->logDebug("$pid is dead already");
			}
		}
	}

	private function spawnWorkers() {
		for ($i = 0; $i < 2; $i++) {
			switch ($pid = pcntl_fork()) {
			case -1: echo "Forking failed"; exit(2);
			case 0: 
				$this->myPid = getmypid();
				$this->logDebug("I'm the child, my PID = $this->myPid");
				$this->installSignals();
				$this->work();
				break;
			default:
				echo "Parent forked into pid $pid\n";
				$this->workerProcesses[$pid] = $pid;
				break;
			}
		}
		
	}

	private function work() {
		echo "Before work starts\n";
		while ( $this->shouldWork ) {
			if ( ! $this->doWork() ) break;
		}
		echo "Worker all done\n";
		exit;
	}
	abstract protected function doWork();

	protected function logDebug($str) {
		$this->logInfo($str);
	}
	protected function logInfo($str) {
		$this->logError($str);
	}
	protected function logError($str) {
		echo "$str\n";
	}

}
class GearmanProcessManager extends ProcessManager {
	private $worker = null;
	private $connectionError = 0;
	private function getWorker() {
		if (!$this->worker) {
			echo "Building new worker\n";
			$this->worker = new GearmanWorker();
			$this->worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
			$this->worker->setTimeout(2500);
			$this->worker->addServer();
			$this->worker->addFunction("reverse", "my_reverse_function");
			$this->worker->addFunction('caps', "my_uppercase");
		}
		return $this->worker;
	}

	
	protected function doWork() {
		$worker = $this->getWorker();
		if ($worker->work()) {
			$pid = getmypid();
			echo "Got a job - $pid \n";
			return true;
		}
		switch ($worker->returnCode()) {
			case GEARMAN_IO_WAIT:
			case GEARMAN_NO_JOBS:
				if (!$worker->wait()) {
					if ($worker->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
						echo "Could not connect to server\n";
						if (5 < $this->connectionError++) {
							echo "Giving up.\n";
							return false;
						}
						sleep(2);
					} else $this->connectionError = 0;
				}
				return true;
			default:
				return false;
		}
	}
}

function my_reverse_function($job)
{
  return strrev($job->workload());
}

function my_uppercase(GearmanJob $job) {
        return strtoupper($job->workload());
}

