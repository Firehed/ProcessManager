<?php

class GearmanProcessManager extends Firehed\ProcessControl\ProcessManager {

	private $worker = null;
	private $reconnects = 0;

	private $functions = [];

	public function addFunction($name, callable $fn) {
		$this->functions[$name] = $fn;
	}

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
					return true;
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
	}

	private function getWorker() {
		if (!$this->worker) {
			$this->getLogger()->debug("Building new worker");
			$this->worker = new GearmanWorker();
			$this->worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
			$this->worker->setTimeout(2500);
			$this->worker->addServer();
			foreach ($this->functions as $name => $fn) {
				$this->worker->addFunction($name, $fn);
			}
		}
		return $this->worker;
	}

}
