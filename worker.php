<?php

include './Daemon.php';
declare(ticks=1);
Daemon::run();



$isParent = getmypid();
$pm = new GearmanProcessManager;

abstract class ProcessManager {

	private $managerPid;
	private $workerProcesses = [];
	private $shouldWork = true;

	function __construct() {
		$this->install_signals();
		$this->managerPid = getmypid();
		$this->spawnWorkers();
		$this->manageWorkers();
	}

	private function manageWorkers() {
		while (1) {
			// Do nothing other than wait for SIGTERM/SIGIN
			sleep(5);
		}
	}

	function install_signals() {
		echo getmypid() . " SIGTERM handler installation\n";
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
	}

	function signal($signo) {
		if (getmypid() == $this->managerPid) {
			echo 'Parent got sigterm'."\n";
			var_dump($this->workerProcesses);
			$this->stop_children(SIGTERM);
			while ($this->workerProcesses) {
				$status = null;
				$exited = pcntl_wait($status, WNOHANG);
				echo "WNOHANG Exited = $exited\n";
				sleep(1);
				unset($this->workerProcesses[$exited]);
			}
			echo "Parent shutting down\n";
			exit;
		}
		else {
			echo 'Child got sigterm'."\n";
			$this->shouldWork = false;
		}
	}

	function stop_children($sig = SIGTERM) {
		foreach ($this->workerProcesses as $pid) {
			echo "Sending SIGTERM to $pid\n";
			posix_kill($pid, $sig);
			if (!posix_kill($pid, 0)) {
				echo "$pid is dead already\n";
			}
		}
	}

	private function spawnWorkers() {
		for ($i = 0; $i < 2; $i++) {
			switch ($pid = pcntl_fork()) {
			case -1: echo "Forking failed"; exit(2);
			case 0: 
				$myPid = getmypid();
				echo "I'm the child, my PID = $myPid\n";
				$this->install_signals();
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

