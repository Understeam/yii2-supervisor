<?php

namespace understeam\supervisor;

use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use Yii;
use yii\console\Controller;
use Symfony\Component\Process\Process;
use yii\helpers\Console;

/**
 * Supervisor Controller runs multiple daemons processes.
 *
 * You can use this controller as linux service. Example of systemd Unit located in `yii.example.service` file
 *
 * @author Anatoly Rugalev <anatoly.rugalev@gmail.com>
 */
class SupervisorController extends Controller
{
    /**
     * @var string location of PHP binary
     */
    public $phpBinary = '/usr/bin/php';
    /**
     * @var string location of `yii` script
     */
    public $yiiFile;
    /**
     * List of commands in following format:
     * [
     *  'reindex' => [
     *      'command' => ['queue/listen', 'reindex'],
     *      'count' => 8,
     * ],
     * @var array defines commands to run as daemons
     */
    public $commands = [];
    /**
     * @var int interval in seconds of process state check
     */
    public $interval = 5;
    /**
     * @var int time to wait process to stop
     */
    public $stopTimeout = 10;
    /**
     * @var int process signal to stop daemons. Default: SIGINT
     */
    public $stopSignal = 3;
    /**
     * @var int process signal to reload daemons. Default: SIGHUP
     */
    public $reloadSignal = 1;
    /**
     * @var string Logging category
     */
    public $logCategory = 'supervisor';
    /**
     * @var bool whether to run the command interactively.
     */
    public $interactive = false;

    /**
     * @var Process[] currently running processes
     */
    private $_processes;

    private $_started = false;

    public function init()
    {
        if ($this->yiiFile === null) {
            if (isset($_SERVER['PHP_SELF'])) {
                $this->yiiFile = realpath($_SERVER['PHP_SELF']);
            } else {
                throw new InvalidConfigException("Cannot autodetect `yii` script path.");
            }
        }
        if ($this->phpBinary === null) {
            if (defined(PHP_BINARY)) {
                $this->phpBinary = PHP_BINARY;
            } else {
                throw new InvalidConfigException("Cannot autodetect PHP binary.");
            }
        }
        parent::init();
    }

    public function actionRun()
    {
        if (DIRECTORY_SEPARATOR == '\\') {
            $this->error("This controller couldn't be used on Windows");
            return self::EXIT_CODE_ERROR;
        }
        if (!function_exists('pcntl_signal')) {
            $this->error("PCNTL extension is required");
            return self::EXIT_CODE_ERROR;
        }
        if (!count($this->commands)) {
            $this->error("No commands to run.");
            return self::EXIT_CODE_ERROR;
        }

        $this->registerSignals();
        while ($this->_stop === false) {
            $this->check();
            $this->_started = true;
            sleep($this->interval);
            pcntl_signal_dispatch();
            if ($this->_reload === true) {
                $this->stopProcesses();
                $this->_reload = false;
                $this->_started = false;
            }
        }
        $this->stopProcesses();
        $this->info("Stopped.");
        return self::EXIT_CODE_NORMAL;
    }

    public function registerSignals()
    {
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal($this->stopSignal, [$this, 'handleShutdown']);
        pcntl_signal($this->reloadSignal, [$this, 'handleReload']);
    }

    private $_stop = false;

    public function handleShutdown()
    {
        $this->_stop = true;
        $this->info("Shutting down...");
    }

    private $_reload = false;

    public function handleReload()
    {
        $this->_reload = true;
        $this->info("Reloading...");
    }

    public function check()
    {
        foreach ($this->commands as $id => $command) {
            if (ArrayHelper::isAssociative($command)) {
                $count = isset($command['count']) ? $command['count'] : 1;
                $command = isset($command['command']) ? $command['command'] : null;
            } else {
                $count = 1;
            }
            if (!$command || $count < 1) {
                continue;
            }
            for ($i = 1; $i <= $count; $i++) {
                if (!isset($this->_processes[$id . '-' . $i])) {
                    $process = null;
                } else {
                    $process = $this->_processes[$id . '-' . $i];
                }
                if (!$process instanceof Process || !$process->isRunning()) {
                    if ($this->_started === true) {
                        $this->error("Process {$id}-{$i} is down.");
                        if ($process instanceof Process) {
                            $this->error(
                                "Command: " . $process->getCommandLine() . "\n"
                                . "Exit code: " . $process->getExitCode() . "\n"
                                . "Error output:\n" . $process->getErrorOutput()
                            );
                        } else {
                            $this->error("Something unexpected happened.");
                        }
                    }
                    $this->info("Running {$id}-{$i} process...");
                    $process = $this->startProcess(implode(' ', $command));
                    $this->_processes[$id . '-' . $i] = $process;
                }
            }
        }
    }

    public function startProcess($command)
    {
        $prefix = $this->phpBinary . " " . Yii::getAlias($this->yiiFile);
        $process = new Process("{$prefix} {$command}");
        $process->setTimeout(null);
        $process->start();
        return $process;
    }

    public function stopProcesses()
    {
        foreach ($this->_processes as $id => $process) {
            if ($process->isRunning()) {
                $this->info("Stopping {$id} process...");
                $process->stop($this->stopTimeout, SIGINT);
            } else {
                $this->info("Process {$id} is not running.");
            }
        }
    }

    public function info($message)
    {
        $this->stdout("[" . date('Y-m-d H:i:s') . "] " . $message, Console::FG_BLUE);
        Yii::info($message, $this->logCategory);
    }

    public function error($message)
    {
        $this->stderr("[" . date('Y-m-d H:i:s') . "] " . $message, Console::FG_RED);
        Yii::error($message, $this->logCategory);
    }
}

