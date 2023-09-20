<?php

namespace Jackalope\Tools\Console\Helper;

/**
 * Helper class for basic jackrabbit server management.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 */
class JackrabbitHelper
{
    protected $jackrabbit_jar;
    protected $workspace_dir;
    protected $port;

    /**
     * construct an instance of the helper.
     *
     * @param string $jackrabbit_jar the path to the jackrabbit server jar file
     * @param string $workspace_dir  if provided this will be used as workspace directory, otherwise the directory of the jar file is used
     * @param int    $port           if provided this will be used as port for HTTP server, otherwise the default is used
     */
    public function __construct($jackrabbit_jar, $workspace_dir = null, $port = null)
    {
        $this->jackrabbit_jar = $jackrabbit_jar;
        $this->workspace_dir = $workspace_dir ? $workspace_dir : dirname($jackrabbit_jar);
        $this->port = $port;
    }

    /**
     * Start the jackrabbit server. If it is already running, silently return.
     */
    public function startServer()
    {
        $pid = $this->getServerPid();
        if (!$pid) {
            chdir($this->workspace_dir);
            $port_option = $this->port ? ' --port '.$this->port : '';
            // TODO: check java is executable
            system("java -jar {$this->jackrabbit_jar} --repo {$this->workspace_dir}/jackrabbit {$port_option} -q> /dev/null &");
        }
    }

    /**
     * Stop the jackrabbit server. If it is not running, silently return.
     */
    public function stopServer()
    {
        $pid = $this->getServerPid();
        if ($pid) {
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * Return true if the jackrabbit server is running, false otherwise.
     *
     * @return bool
     */
    public function isServerRunning()
    {
        return '' !== $this->getServerPid();
    }

    public function getServerPid()
    {
        $pid = trim(shell_exec("pgrep -f -n 'java \-jar {$this->jackrabbit_jar}'"));

        // TODO: check it's a valid pid
        return $pid;
    }
}
