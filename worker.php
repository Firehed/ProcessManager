<?php

include './Daemon.php';
declare(ticks=1);
Daemon::run();

$pm = new GearmanProcessManager;

abstract class ProcessManager {

	private $managerPid;
	private $workerProcesses = [];
	private $shouldWork = true;

	protected $myPid;

	public function __construct() {
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

	public function signal($signo) {
		if ($this->isParent()) {
			$this->logInfo('Parent got sigterm');
			$this->logDebug("Children: " . print_r($this->workerProcesses, true));
			$this->stopChildren(SIGTERM);
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
			$this->stopWorking();
		}
	}

	protected function stopWorking() {
		$this->shouldWork = false;
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
	}

	private function stopChildren($sig = SIGTERM) {
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
			case -1:
				$this->logError("Spawning worker failed");
				exit(2);
			case 0: 
				$this->myPid = getmypid();
				$this->logInfo("I'm the child, my PID = $this->myPid");
				$this->installSignals();
				$this->work();
				break;
			default:
				$this->logDebug("Parent created child with pid $pid");
				$this->workerProcesses[$pid] = $pid;
				break;
			}
		}
	}

	private function work() {
		$this->logDebug("Child $this->myPid about to start work");
		while ( $this->shouldWork ) {
			$this->doWork();
		}
		$this->logInfo("Child $this->myPid exiting");
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
	private $reconnects = 0;

	protected function doWork() {
		$worker = $this->getWorker();
		if ($worker->work()) {
			$this->logDebug("$this->myPid processed a job");
			$this->reconnects = 0;
			return true;
		}
		switch ($worker->returnCode()) {
			case GEARMAN_IO_WAIT:
			case GEARMAN_NO_JOBS:
				if (@$worker->wait()) {
					$this->logDebug("$this->myPid waited with no error");
					$this->reconnects = 0;
					return true;
				}
				if ($worker->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
					$this->logError("$this->myPid Connection to gearmand server failed");
					if (++$this->reconnects >= 5) {
						$this->logError("$this->myPid Giving up");
						$this->stopWorking();
					}
					else {
						sleep(2);
					}
				}
				break;
			default:
				$this->logError("$this->myPid exiting after getting code {$worker->returnCode()}");
				$this->stopWorking();
		}
	}

	private function getWorker() {
		if (!$this->worker) {
			$this->logDebug("Building new worker");
			$this->worker = new GearmanWorker();
			$this->worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
			$this->worker->setTimeout(2500);
			$this->worker->addServer();
			$this->worker->addFunction("reverse", "my_reverse_function");
			$this->worker->addFunction('caps', "my_uppercase");
		}
		return $this->worker;
	}

}

function my_reverse_function($job)
{
  return strrev($job->workload());
}

function my_uppercase(GearmanJob $job) {
        return strtoupper($job->workload());
}

