<?php

namespace Firehed\ProcessControl;

abstract class ProcessManager {

	use \Psr\Log\LoggerAwareTrait;

	private $managerPid;
	private $workerProcesses = [];
	private $shouldWork = true;
	private $workers = 1;


	protected $myPid;

	public function __construct() {
		$this->managerPid = $this->myPid = getmypid();
		$this->setLogger(new \Psr\Log\NullLogger);
		$this->installSignals();
	}

	public function setWorkerCount($count) {
		if (!is_int($count)) {
			throw new InvalidArgumentException("Integer argument is required");
		}
		if ($count < 1) {
			throw new InvalidArgumentException("Must have at least 1 worker");
		}
		$this->workers = $count;
	}

	final public function start() {
		$this->manageWorkers();
	}

	private function cleanChildren() {
		$status = null;
		if ($exited = pcntl_wait($status, WNOHANG)) {
			unset($this->workerProcesses[$exited]);
			$this->logInfo("Worker $exited got WNOHANG during normal operation");
		}
	}

	abstract protected function doWork();

	private function installSignals() {
		$this->logDebug("$this->myPid SIGTERM handler installation");
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
	}

	protected function logDebug($str) {
		// $this->logInfo($str);
		$this->logger->debug($str);
	}

	protected function logError($str) {
		// echo "$str\n";
		$this->logger->error($str);
	}

	protected function logInfo($str) {
		// $this->logError($str);
		$this->logger->info($str);
	}

	private function manageWorkers() {
		while ($this->shouldWork) {
			// Do nothing other than wait for SIGTERM/SIGIN
			if (count($this->workerProcesses) < $this->workers) {
				$this->spawnWorker();
			}
			else {
				$this->cleanChildren();
				sleep(5);
			}
		}
	}

	public function signal($signo) {
		if ($this->isParent()) {
			$this->stopWorking();
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

	private function spawnWorker() {
		$this->logInfo("Creating a new worker");
		switch ($pid = pcntl_fork()) {
		case -1: // Failed
			$this->logError("Spawning worker failed");
			exit(2);
		case 0:  // Child
			$this->myPid = getmypid();
			$this->logInfo("$this->myPid created");
			$this->installSignals();
			$this->work();
			break;
		default: // Parent
			$this->logDebug("Parent created child with pid $pid");
			$this->workerProcesses[$pid] = $pid;
			break;
		}
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

	protected function stopWorking() {
		$this->shouldWork = false;
	}

	private function work() {
		$this->logDebug("Child $this->myPid about to start work");
		while ( $this->shouldWork ) {
			$_SERVER['REQUEST_TIME'] = time();
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
			$this->doWork();
		}
		$this->logInfo("Child $this->myPid exiting");
		exit;
	}

}
