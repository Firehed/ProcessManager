<?php

namespace Firehed\ProcessControl;

use GearmanWorker;

class GearmanWorkerManager extends ProcessManager {

	// GearmanWorker object
	private $worker = null;
	// Number of attemps to reconnect to gearmand
	private $reconnects = 0;
	// [worker name => []<gm function name>
	private $config = array();
	// [worker name => count]
	private $runCounts = [];
	// [worker name => nice];
	private $niceties = [];
	// Functions this worker will expose to Gearman (set in child only)
	private $myCallbacks = [];
	// [ini function name => callable]
	private $registeredFunctions = [];
	// gearmand servers
	private $servers = '127.0.0.1:4730';

	public function setServers(array $servers) {
		// Todo: validate?
		$this->servers = implode(',', $servers);
		return $this;
	}

	public function setConfigFile($path) {
		$config = parse_ini_file($path, $process_sections=true);
		$defaults =
		[ 'count' => 0
		, 'functions' => []
		, 'runcount' => 0
		, 'nice' => 0
		];
		$types = [];
		foreach ($config as $section => $values) {
			$values += $defaults;
			if ($values['count'] < 1) {
				throw new \Exception("[$section] count must be a postive number");
			}
			$types[$section] = (int) $values['count'];

			// Todo: automatic support for "all" and "unhandled"
			if (!$values['functions'] || !is_array($values['functions'])) {
				throw new \Exception("At least one function s required");
			}
			$this->config[$section] = $values['functions'];
			$this->runCounts[$section] = (int) $values['runcount'];
			$this->niceties[$section] = (int) $values['nice'];
		}

		// Set up parent management config
		$this->setWorkerTypes($types);
		return $this;
	}

	protected function beforeWork() {
		foreach ($this->config[$this->workerType] as $name) {
			$this->myCallbacks[$name] = $this->registeredFunctions[$name];
		}
		$this->setRunCount($this->runCounts[$this->workerType]);
		$this->setNice($this->niceties[$this->workerType]);
	}

	public function registerFunction($name, callable $fn) {
		$this->registeredFunctions[$name] = $fn;
		return $this;
	}

	/** @return true if work was attempted, false otherwise */
	protected function doWork() {
		$worker = $this->getWorker();
		if ($worker->work()) {
			$this->getLogger()->debug("$this->myPid processed a job");
			$this->reconnects = 0;
			return true;
		}
		switch ($worker->returnCode()) {
			case GEARMAN_IO_WAIT:
			case GEARMAN_NO_JOBS:
				if (@$worker->wait()) {
					$this->getLogger()->debug("$this->myPid waited with no error");
					$this->reconnects = 0;
					return false;
				}
				if ($worker->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
					$this->getLogger()->error("$this->myPid Connection to gearmand server failed");
					if (++$this->reconnects >= 5) {
						$this->getLogger()->error("$this->myPid Giving up");
						$this->stopWorking();
					}
					else {
						sleep(2);
					}
				}
				break;
			default:
				$this->getLogger()->error("$this->myPid exiting after getting code {$worker->returnCode()}");
				$this->stopWorking();
		}
		return false; // Assume no work was done
	}

	private function getWorker() {
		if (!$this->worker) {
			$this->getLogger()->debug("Building new worker");
			$this->worker = new GearmanWorker();
			$this->worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
			$this->worker->setTimeout(2500);
			$this->worker->addServers($this->servers);
			foreach ($this->myCallbacks as $name => $cb) {
				$this->worker->addFunction($name, $cb);
			}
		}
		return $this->worker;
	}

}
