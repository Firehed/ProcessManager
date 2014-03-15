<?php

namespace Firehed\ProcessControl;

abstract class ProcessManager {

	use \Psr\Log\LoggerAwareTrait;

	private $managerPid;
	private $workerProcesses = []; // pid => type
	private $shouldWork = false;
	private $workers = 0;
	private $workerTypes = []; // name => count to spawn
	private $runCount = 0; // child: number of times to run before respawn
	private $nice = 0; // child: process nice level (see: man nice)
	private $roundsComplete = 0; // child: number of times work completed

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
		$this->shouldWork = true;
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

	/** @return true if work was done, false otherwise */
	abstract protected function doWork();

	private function installSignals() {
		$this->getLogger()->debug("Installing signals");
		pcntl_signal(SIGTERM, [$this,'signal']);
		pcntl_signal(SIGINT,  [$this,'signal']);
		pcntl_signal(SIGTRAP, [$this,'signal']);
		pcntl_signal(SIGHUP,  [$this,'signal']);
		pcntl_signal(SIGCHLD, [$this,'signal']);
	}

	private function isParent() {
		return $this->myPid == $this->managerPid;
	}

	private function manageWorkers() {
		while ($this->shouldWork) {
			// Do nothing other than wait for SIGTERM/SIGINT
			if (count($this->workerProcesses) < $this->workers) {
				$currentWorkers = array_count_values($this->workerProcesses);
				foreach ($this->workerTypes as $type => $count) {
					if (!isset($currentWorkers[$type]) || $currentWorkers[$type] < $count) {
						$this->spawnWorker($type);
					}
				}
			}
			else {
				// Just in case a SIGCHLD was missed
				$this->cleanChildren();
			}
			sleep(1);
		}
		$this->getLogger()->debug("Stopping work, waiting for children");
		// For magical unixey reasons I don't understand, simply listening for
		// SIGHCLD isn't reliable enough here, so we have to spin on this and
		// manually watch for children to reap. Might just be a weird race
		// condition. Dunno.
		while ($this->workerProcesses) {
			if (!$this->cleanChildren()) {
				sleep(1);
			}
		}
		$this->getLogger()->debug("All children have stopped");
	}

	public function signal($signo) {
		switch ($signo) {
		case SIGTERM:
		case SIGINT:
			$this->handleSigterm();
			break;
		case SIGHUP:
			$this->handleSighup();
			break;
		case SIGCHLD:
			$this->cleanChildren();
			break;
		case SIGTRAP:
			$e = new \Exception;
			file_put_contents(sys_get_temp_dir().'/pm_backtrace_'.$this->myPid,
				$e->getTraceAsString());
			break;
		default:
			$this->getLogger()->error("No signal handler for $signo");
			break;
		}
	}

	private function handleSighup() {
		// Ignore SIGHUP unless a term request has already been received
		if ($this->shouldWork) {
			return;
		}
		if ($this->isParent()) {
			// Might move the handle second SIGTERM logic in here, but "SIGTERM
			// SIGTERM" seems like a more natural and slightly less error-prome
			// way to handle things, as the controlling terminal could SIGHUP
			// the parent unexpectedly.
		}
		else { // Child
			$this->getLogger()->info("Child received SIGHUP;".
				" detaching to finish the current job then exiting.");
			$newpid = pcntl_fork();
			if (-1 === $newpid) {
				$this->getLogger()->error("Child detach-forking failed completely");
			}
			elseif (0 === $newpid) {
				// Detached child, continue as normal
			}
			else {
				exit; // Original child attacked to parent
			}
			/*
			 * Detaching from the terminal doesn't seem to do anything useful,
			 * especially since this is normally going to be run as a daemon
			if (-1 === posix_setsid()) {
				$this->getLogger()->error("Child could not detach from parent".
					" to finish last piece of work");
			}
			*/
		}
	}

	private function handleSigterm() {
		if ($this->isParent()) {
			$this->getLogger()->info('Parent got sigterm/sigint');
			$this->getLogger()->debug("Children: ".
				print_r(array_keys($this->workerProcesses), true));
			if (!$this->shouldWork) {
				$this->getLogger()->debug(
					"Parent got second SIGTERM, telling children to detach");
				$this->stopChildren(SIGHUP);
				return;
			}
			$this->stopWorking();
			$this->stopChildren(SIGTERM);
		}
		else {
			$this->getLogger()->info("Child $this->myPid received SIGTERM; stopping work");
			$this->stopWorking();
		}
	}

	private function spawnWorker($type) {
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

	private function stopChildren($sig) {
		foreach ($this->workerProcesses as $pid => $type) {
			// I'd prefer logging the actual signal name here but there's not
			// a one-liner to convert AFAIK
			$this->getLogger()->debug("Sending signal $sig to $pid");
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

	protected function setRunCount($count) {
		if (!is_int($count)) {
			throw new \Exception("Count must be an integer");
		}
		elseif ($count < 0) {
			throw new \Exception("Count must be 0 or greater");
		}
		$this->runCount = $count;
	}

	protected function setNice($level) {
		if (!is_int($level) || $level > 20 || $level < -20) {
			throw new \Exception("Nice must be an int between -20 and 20");
		}
		$this->nice = $level;
	}

	private function work() {
		$this->getLogger()->debug("Child $this->myPid about to start work");
		if ($this->nice) {
			$this->getLogger()->debug("Child being reniced to $this->nice");
			proc_nice($this->nice);
		}
		while ($this->shouldWork) {
			$_SERVER['REQUEST_TIME'] = time();
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
			if ($this->doWork()) {
				$this->roundsComplete++;
			}
			// If runCount is 0, go indefinitely. Otherwise stop after runCount
			if ($this->runCount && $this->roundsComplete >= $this->runCount) {
				$this->stopWorking();
			}
		}
		$this->getLogger()->info("Child has stopped working and will exit");
		exit;
	}

}
