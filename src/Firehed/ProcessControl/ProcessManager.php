<?php

namespace Firehed\ProcessControl;

abstract class ProcessManager {

	use \Psr\Log\LoggerAwareTrait;

	private $managerPid;
	private $workerProcesses = []; // pid => pid
	private $shouldWork = true;
	private $workers = 1;


	protected $myPid;

	public function __construct(\Psr\Log\LoggerInterface $logger = null) {
		$this->managerPid = $this->myPid = getmypid();
		if ($logger) {
			$this->setLogger($logger);
		}
		else {
			$this->setLogger(new \Psr\Log\NullLogger);
		}
		$this->installSignals();
	}

	protected function getLogger() {
		return $this->logger;
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
			$this->getLogger()->info("Worker $exited got WNOHANG during normal operation");
		}
	}

	abstract protected function doWork();

	private function installSignals() {
		$this->getLogger()->debug("$this->myPid SIGTERM handler installation");
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
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
		switch ($signo) {
		case SIGTERM:
		case SIGINT:
			$this->handleSigterm();
			break;
		}
	}

	private function handleSigterm() {
		if ($this->isParent()) {
			$this->stopWorking();
			$this->getLogger()->info('Parent got sigterm');
			$this->getLogger()->debug("Children: ".
				print_r(array_keys($this->workerProcesses), true));
			$this->stopChildren(SIGTERM);
			while ($this->workerProcesses) {
				$status = null;
				if ($exited = pcntl_wait($status, WNOHANG)) {
					unset($this->workerProcesses[$exited]);
					$this->getLogger()->debug("Worker $exited got WNOHANG");
				}
				else {
					sleep(1);
				}
			}
			$this->getLogger()->info("Parent shutting down");
			exit;
		}
		else {
			$this->getLogger()->info("Child $this->myPid received SIGTERM; stopping work");
			$this->stopWorking();
		}
	}

	private function spawnWorker() {
		$this->getLogger()->info("Creating a new worker");
		switch ($pid = pcntl_fork()) {
		case -1: // Failed
			$this->getLogger()->error("Spawning worker failed");
			exit(2);
		case 0:  // Child
			$this->myPid = getmypid();
			$this->getLogger()->info("$this->myPid created");
			$this->installSignals();
			$this->work();
			break;
		default: // Parent
			$this->getLogger()->debug("Parent created child with pid $pid");
			$this->workerProcesses[$pid] = $pid;
			break;
		}
	}

	private function stopChildren($sig = SIGTERM) {
		foreach ($this->workerProcesses as $pid => $pidCopy) {
			$this->getLogger()->debug("Sending SIGTERM to $pid");
			posix_kill($pid, $sig);
			if (!posix_kill($pid, 0)) {
				$this->getLogger()->debug("$pid is dead already");
			}
		}
	}

	protected function stopWorking() {
		$this->shouldWork = false;
	}

	private function work() {
		$this->getLogger()->debug("Child $this->myPid about to start work");
		while ( $this->shouldWork ) {
			$_SERVER['REQUEST_TIME'] = time();
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
			$this->doWork();
		}
		$this->getLogger()->info("Child $this->myPid exiting");
		exit;
	}

}
