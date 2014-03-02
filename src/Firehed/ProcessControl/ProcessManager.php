<?php

namespace Firehed\ProcessControl;

abstract class ProcessManager {

	use \Psr\Log\LoggerAwareTrait;

	private $managerPid;
	private $workerProcesses = []; // pid => type
	private $shouldWork = true;
	private $workers = 0;
	private $workerTypes = []; // name => count to spawn

	protected $myPid;
	protected $workerType;

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

	public function setWorkerTypes(array $types) {
		$total = 0;
		foreach ($types as $name => $count) {
			if (!is_string($name)) {
				throw new \Exception("Worker type name must be a string");
			}
			if (!is_int($count) || $count < 1) {
				throw new \Exception("Worker type count must be a positive integer");
			}
			$total += $count;
		}
		$this->workerTypes = $types;
		$this->workers = $total;
		return $this;
	}

	final public function start() {
		$this->manageWorkers();
	}

	/** @return bool did a child exit? */
	private function cleanChildren() {
		$status = null;
		if ($exited = pcntl_wait($status, WNOHANG)) {
			unset($this->workerProcesses[$exited]);
			$this->getLogger()->info("Worker $exited got WNOHANG during normal operation");
			return true;
		}
		return false;
	}

	abstract protected function doWork();

	private function installSignals() {
		$this->getLogger()->debug("$this->myPid SIGTERM handler installation");
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
		pcntl_signal(SIGTRAP, [$this,'signal']);
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
	}

	private function manageWorkers() {
		while ($this->shouldWork) {
			// Do nothing other than wait for SIGTERM/SIGINT
			if (count($this->workerProcesses) < $this->workers) {
				$didSpawn = false;
				$currentWorkers = array_count_values($this->workerProcesses);
				foreach ($this->workerTypes as $type => $count) {
					if (!isset($currentWorkers[$type]) || $currentWorkers[$type] < $count) {
						$this->spawnWorker($type);
						$didSpawn = true;
						break;
					}
				}
				if (!$didSpawn) {
					$this->spawnWorker();
				}
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
		case SIGTRAP: // fail for now
		default:
			$this->getLogger()->error("No signal handler for $signo");
			break;
		}
	}

	private function handleSigterm() {
		if ($this->isParent()) {
			$this->getLogger()->info('Parent got sigterm');
			$this->getLogger()->debug("Children: ".
				print_r(array_keys($this->workerProcesses), true));
			$this->stopWorking();
			$this->stopChildren(SIGTERM);
			while ($this->workerProcesses) {
				if (!$this->cleanChildren()) {
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

	private function spawnWorker($type = '_generic') {

		$this->getLogger()->info("Creating a new worker of type $type");
		switch ($pid = pcntl_fork()) {
		case -1: // Failed
			$this->getLogger()->error("Spawning worker failed");
			exit(2);
		case 0:  // Child
			$this->myPid = getmypid();
			$this->workerType = $type;
			$this->getLogger()->info("$this->myPid created");
			// Available since PHP 5.5
			if (function_exists('cli_set_process_title')) {
				cli_set_process_title($type);
			}
			$this->installSignals();
			$this->beforeWork();
			$this->work();
			break;
		default: // Parent
			$this->getLogger()->debug("Parent created child with pid $pid");
			$this->workerProcesses[$pid] = $type;
			break;
		}
	}

	private function stopChildren($sig = SIGTERM) {
		foreach ($this->workerProcesses as $pid => $type) {
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

	protected function beforeWork() {
		// hook, intentionally left empty
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
