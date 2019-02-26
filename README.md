# Process Control Tools

## Requirements
* `posix` and `pcntl` extensions
* PHP5.4+ (Uses modern syntax)
* Basic knowledge of PHP on the command line

# Daemon
A really useful tool with a very boring name. Daemonize your PHP scripts with two lines of code.


## Usage
Code:

	<?php
	// Preferred:
	require 'vendor/autoload.php'; // composer
	declare(ticks=1);
    (new Firehed\ProcessControl\Daemon)
        ->setUser('sites')
        ->setPidFileLocation('/var/run/gearman-manager2.pid')
        ->setStdoutFileLocation(sys_get_temp_dir().'/my.log')
        ->setStdErrFileLocation('/dev/null')
        ->setProcessName(basename(__FILE__).' master process')
        ->autoRun();
	// The rest of your original script

CLI:

	php yourscript.php {status|start|stop|restart|reload|kill}

Yes, it's that simple.

### Actions
* Status: Check the status of the process. Returns:
	* 0 if running
	* 1 if dead but pidfile is hanging around
	* 3 if stopped
* Start: Start the daemon
* Stop: Stop the daemon gracefully via SIGTERM
* Restart: Stop (if running) and start
* Reload: Send SIGUSR1 to daemon (you need to implement a reload function, see below)
* Kill: Kill the daemon via SIGKILL (kill -9)

## Options
* `setProcessName($string)`: Set the process name as it will appear in utilities such as `top`. This is only supported under PHP5.5+.
* `setPidFileLocation($path)`: Specify the location of the pid file. This file stores the process id when the daemon is running, and goes away when the daemon stops.
* `setStdoutFileLocation($path)`: File where anything that would have been written to `STDOUT` (`echo`, `print`, etc) goes.
* `setStdErrFileLocation($path)`: File where anything that would have been written to `STDERR` goes. It appears that `display_errors` no longer writes to `STDERR` after daemonizing, so setting this to `/dev/null` is pretty safe.
* `setUser($system_user)`: If you want to have the process run as a lower-security user, specify the username here. This is especially helpful if you start the daemon on system with `chkconfig` and `/etc/init.d`, since those run as root.

To come later(?):
* Verbose output
* Synchronous mode (do not daemonize for debugging)
* Log file configuration

## Useful tips

* STDOUT (echo, print) is redirected to the log file.
* The "reload" command won't do anything without installing a handler for SIGUSR1. Examples are due shortly.


## Known Issues

* STDERR doesn't appear to go anywhere, despite opening a logfile for it.
* The script can't set up "reload" bindings automatically. This is a PHP limitation: "The declare construct can also be used in the global scope, affecting all code following it (**however if the file with declare was included then it does not affect the parent file**)". [http://docs.php.net/manual/en/control-structures.declare.php]()
