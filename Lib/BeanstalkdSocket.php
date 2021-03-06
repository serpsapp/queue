<?php
/**
 * Beanstalkd Socket File
 *
 * A low-level interface to the beanstalkd queue service.
 *
 * Copyright (c) 2009-2010 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 *
 * PHP version 5
 *
 * @package    queue
 * @subpackage queue.libs
 * @copyright  2009 David Persson <davidpersson@gmx.de>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link       http://github.com/davidpersson/queue
 */

/**
 * Beanstalkd Socket Class
 *
 * Implements the beanstalkd protocol spec 1.2
 *
 * @package    queue
 * @subpackage queue.libs
 * @link       http://github.com/kr/beanstalkd
 */
class BeanstalkdSocket {

	/**
	 * Holds a boolean indicating whether a connection to the server is
	 * currently established or not.
	 *
	 * @var boolean
	 */
	public $connected = false;

	/**
	 * Holds configuration values.
	 *
	 * @var array
	 */
	protected $_config = array();

	/**
	 * The current connection resource handle (if any).
	 *
	 * @var resource
	 */
	protected $_connection;

	/**
	 * Generated errors. Will hold a maximum of 200 error messages at any time
	 * to prevent pilling up messages and using more and more memory. This is
	 * especially important if this class is used in long-running workers.
	 *
	 * @see Socket_Beanstalk::errors()
	 * @see Socket_Beanstalk::_error()
	 * @var array
	 */
	protected $_errors = array();

	/**
	 * Last tube "use"d.
	 */
	protected $_tube = null;

	/**
	 * FIFO of callbacks for consecutive put_async calls
	 */
	protected $_callbacks = array();

	/**
	 * FIFO of jobs associated with callbacks
	 */
	protected $_jobs = array();

	/**
	 * Constructor.
	 *
	 * @param array $config An array of configuration values:
	 *        - `'persistent'`  Whether to make the connection persistent or
	 *                          not, defaults to `true` as the FAQ recommends
	 *                          persistent connections.
	 *        - `'host'`        The beanstalk server hostname or IP address to
	 *                          connect to, defaults to `127.0.0.1`.
	 *        - `'port'`        The port of the server to connect to, defaults
	 *                          to `11300`.
	 *        - `'timeout'`     Timeout in seconds when establishing the
	 *                          connection, defaults to `1`.
	 * @return void
	 */
	public function __construct(array $config = array()) {
		$defaults = array(
			'persistent' => true,
			'host' => '127.0.0.1',
			'port' => 11300,
			'timeout' => 1
		);
		$this->_config = $config + $defaults;
	}

	/**
	 * Destructor, disconnects from the server.
	 *
	 * @return void
	 */
	public function __destruct() {
		$this->disconnect();
	}

	/**
	 * Initiates a socket connection to the beanstalk server. The resulting
	 * stream will not have any timeout set on it. Which means it can wait an
	 * unlimited amount of time until a packet becomes available. This is
	 * required for doing blocking reads.
	 *
	 * @see Socket_Beanstalk::$_connection
	 * @see Socket_Beanstalk::reserve()
	 * @return boolean `true` if the connection was established, `false` otherwise.
	 */
	public function connect() {
		if (isset($this->_connection)) {
			$this->disconnect();
		}

		$function = $this->_config['persistent'] ? 'pfsockopen' : 'fsockopen';
		$params = array($this->_config['host'], $this->_config['port'], &$errNum, &$errStr);

		if ($this->_config['timeout']) {
			$params[] = $this->_config['timeout'];
		}
		$this->_connection = @call_user_func_array($function, $params);

		if (!empty($errNum) || !empty($errStr)) {
			$this->_error("{$errNum}: {$errStr}");
		}

		$this->connected = is_resource($this->_connection);

		if ($this->connected) {
			stream_set_timeout($this->_connection, -1);
			$this->_tube = null;
		}
		return $this->connected;
	}

	/**
	 * Closes the connection to the beanstalk server.
	 *
	 * @return boolean `true` if diconnecting was successful.
	 */
	public function disconnect() {
		$this->drain();
		if (!is_resource($this->_connection)) {
			$this->connected = false;
		} else {
			$this->connected = !fclose($this->_connection);

			if (!$this->connected) {
				$this->_connection = null;
			}
		}
		return !$this->connected;
	}

	/**
	 * Returns collected error messages.
	 *
	 * @return array An array of error messages.
	 */
	public function errors() {
		return $this->_errors;
	}

	/**
	 * Pushes an error message to `Beanstalk::$_errors`. Ensures
	 * that at any point there are not more than 200 messages.
	 *
	 * @param string $message The error message.
	 * @return void
	 */
	protected function _error($message) {
		if (count($this->_errors) >= 200) {
			array_shift($this->_errors);
		}
		array_push($this->_errors, $message);
	}

	/**
	 * Writes a packet to the socket. Prior to writing to the socket will check
	 * for availability of the connection.
	 *
	 * @param string $data
	 * @return integer|boolean number of written bytes or `false` on error.
	 */
	protected function _write($data) {
		if (!$this->connected && !$this->connect()) {
			return false;
		}

		$data .= "\r\n";
		$written = fwrite($this->_connection, $data, strlen($data));
		if (($written === false || $written === 0) && feof($this->_connection)) {
			$this->_error('Write failed: ' .
			    socket_strerror(socket_last_error($this->_connection)));
			$this->disconnect();
			return false;
		}
		return $written;
	}

	/**
	 * Reads a packet from the socket. Prior to reading from the socket will
	 * check for availability of the connection.
	 *
	 * @param int $length Number of bytes to read.
	 * @return string|boolean Data or `false` on error.
	 */
	protected function _read($length = null) {
		if (!$this->connected && !$this->connect()) {
			return false;
		}
		if ($length) {
			if (feof($this->_connection)) {
				$this->_error('Read failed: ' .
				    socket_strerror(socket_last_error($this->_connection)));
				$this->disconnect();
				return false;
			}
			$data = stream_get_contents($this->_connection, $length + 2);
			$meta = stream_get_meta_data($this->_connection);

			if ($meta['timed_out']) {
				$this->_error('Connection timed out.');
				return false;
			}
			$packet = rtrim($data, "\r\n");
		} else {
			$data = fgets($this->_connection, 16384);
			$packet = rtrim($data, "\r\n");
			//$packet = stream_get_line($this->_connection, 16384, "\r\n");
		}
		return $packet;
	}

	/* Producer Commands */

	/**
	 * The `put` command is for any process that wants to insert a job into the queue.
	 *
	 * @param integer $pri Jobs with smaller priority values will be scheduled
	 *        before jobs with larger priorities. The most urgent priority is
	 *        0; the least urgent priority is 4294967295.
	 * @param integer $delay Seconds to wait before putting the job in the
	 *        ready queue.  The job will be in the "delayed" state during this time.
	 * @param integer $ttr Time to run - Number of seconds to allow a worker to
	 *        run this job.  The minimum ttr is 1.
	 * @param string $data The job body.
	 * @return integer|boolean `false` on error otherwise an integer indicating
	 *         the job id.
	 */
	public function put($pri, $delay, $ttr, $data) {
		if (!$this->_write(sprintf("put %d %d %d %d\r\n%s", $pri, $delay, $ttr, strlen($data),$data))) return false;

		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'INSERTED':
			case 'BURIED':
				return (integer)strtok(' '); // job id
			case 'EXPECTED_CRLF':
			case 'JOB_TOO_BIG':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * The `put_async` is for high-throughput producers who do not immediately
	 * need the job ID.
	 *
	 * This does not currently interoperate with put(), so you should not mix
	 * async and sync calls, or the results are undefined.
	 *
	 * The results, if not available immediately, will be checked on the next
	 * invocation of put_async() or on the next call to poll().
	 *
	 * @param $callback an optional function to be called with the id of the
	 * job when it is known, or with false if an error occurred.
	 * @return true iff the write succeeded.
	 */
	public function put_async($pri, $delay, $ttr, $data, $callback) {
		if (!$this->_write(sprintf("put %d %d %d %d\r\n%s", $pri, $delay, $ttr, strlen($data),$data))) return false;
		array_push($this->_callbacks, $callback);
		array_push($this->_jobs, $data);
		$this->poll();
		return true;
	}

	/**
	 * Polls the connection for responses from a previous put_async.
	 * If any responses are available, their callbacks will be called.
	 *
	 * @param blocking if true, block for just one callback and service it.  if
	 * false, do not block, but service all callbacks which may be available.
	 * @return true if any callbacks were serviced
	 */
	public function poll($blocking = false) {
		stream_set_blocking($this->_connection, $blocking);
		$ret = false;
		$loop = true;
		while ($loop && ($line = $this->_read())) {
			$ret = true;
			$callback = array_shift($this->_callbacks);
			$job = array_shift($this->_jobs);
			if ($callback) {
				$status = strtok($line, ' ');
				switch ($status) {
				case 'INSERTED':
				case 'BURIED':
					$jobid = (integer)strtok(' ');
					$callback($job, $jobid);
					break;
				case 'EXPECTED_CRLF':
				case 'JOB_TOO_BIG':
				default:
					$this->_error($status);
					$callback($job, false);
				}
			}
			$loop = !$blocking;
		}
		stream_set_blocking($this->_connection, true);
		return $ret;
	}

	/**
	 * Blocks repeatedly until all callbacks have been serviced or the
	 * connection is closed.
	 */
	public function drain() {
		while (is_resource($this->_connection) &&
			   !feof($this->_connection) &&
			   (count($this->_callbacks) > 0)
			   ) {
			$this->poll(true);
		}
		while (count($this->_callbacks) > 0) {
			$callback = array_shift($this->_callbacks);
			$job = array_shift($this->_jobs);
			print_r("Erroring!");
			$callback($job, false);
		}
	}

	/**
	 * The `use` command is for producers. Subsequent put commands will put jobs into
	 * the tube specified by this command. If no use command has been issued, jobs
	 * will be put into the tube named `default`.
	 *
	 * Please note that while obviously this method should better be named
	 * `use` it is not. This is because `use` is a reserved keyword in PHP.
	 *
	 * @param string $tube A name at most 200 bytes. It specifies the tube to
	 *        use.  If the tube does not exist, it will be created.
	 * @return string|boolean `false` on error otherwise the name of the tube.
	 */
	public function choose($tube) {
		if ($tube == $this->_tube) {
			return $tube;
		}
		$this->_tube = $tube;

		if (!$this->_write(sprintf('use %s', $tube))) {
			return false;
		}
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'USING':
				return strtok(' ');
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Alias for choose.
	 *
	 * @see Socket_Beanstalk::choose()
	 * @param string $tube
	 * @return string|boolean
	 */
	public function useTube($tube) {
		return $this->choose($tube);
	}

	/* Worker Commands */

	/**
	 * Reserve a job (with a timeout)
	 *
	 * @param integer $timeout If given specifies number of seconds to wait for
	 *        a job. 0 returns immediately.
	 * @return array|false `false` on error otherwise an array holding job id
	 *         and body.
	 */
	public function reserve($timeout = null) {
		if (isset($timeout)) {
			if (!$this->_write(sprintf('reserve-with-timeout %d', $timeout))) return false;
		} else {
			if (!$this->_write('reserve')) return false;
		}
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'RESERVED':
				return array(
					'id' => (integer)strtok(' '),
					'body' => $this->_read((integer)strtok(' '))
				);
			case 'DEADLINE_SOON':
			case 'TIMED_OUT':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Removes a job from the server entirely.
	 *
	 * @param integer $id The id of the job.
	 * @return boolean `false` on error, `true` on success.
	 */
	public function delete($id) {
		if (!$this->_write(sprintf('delete %d', $id))) return false;
		$status = $this->_read();

		switch ($status) {
			case 'DELETED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Puts a reserved job back into the ready queue.
	 *
	 * @param integer $id The id of the job.
	 * @param integer $pri Priority to assign to the job.
	 * @param integer $delay Number of seconds to wait before putting the job in the ready queue.
	 * @return boolean `false` on error, `true` on success.
	 */
	public function release($id, $pri, $delay) {
		if (!$this->_write(sprintf('release %d %d %d', $id, $pri, $delay))) return false;
		$status = $this->_read();

		switch ($status) {
			case 'RELEASED':
			case 'BURIED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Puts a job into the `buried` state Buried jobs are put into a FIFO
	 * linked list and will not be touched until a client kicks them.
	 *
	 * @param integer $id The id of the job.
	 * @param integer $pri *New* priority to assign to the job.
	 * @return boolean `false` on error, `true` on success.
	 */
	public function bury($id, $pri) {
		if (!$this->_write(sprintf('bury %d %d', $id, $pri))) return false;
		$status = $this->_read();

		switch ($status) {
			case 'BURIED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Allows a worker to request more time to work on a job
	 *
	 * @param integer $id The id of the job.
	 * @return boolean `false` on error, `true` on success.
	 */
	public function touch($id) {
		if (!$this->_write(sprintf('touch %d', $id))) return false;
		$status = $this->_read();

		switch ($status) {
			case 'TOUCHED':
				return true;
			case 'NOT_TOUCHED':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Adds the named tube to the watch list for the current
	 * connection.
	 *
	 * @param string $tube Name of tube to watch.
	 * @return integer|boolean `false` on error otherwise number of tubes in watch list.
	 */
	public function watch($tube) {
		if (!$this->_write(sprintf('watch %s', $tube))) return false;
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'WATCHING':
				return (integer)strtok(' ');
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Remove the named tube from the watch list.
	 *
	 * @param string $tube Name of tube to ignore.
	 * @return integer|boolean `false` on error otherwise number of tubes in watch list.
	 */
	public function ignore($tube) {
		if (!$this->_write(sprintf('ignore %s', $tube))) return false;
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'WATCHING':
				return (integer)strtok(' ');
			case 'NOT_IGNORED':
			default:
				$this->_error($status);
				return false;
		}
	}

	/* Other Commands */

	/**
	 * Inspect a job by its id.
	 *
	 * @param integer $id The id of the job.
	 * @return string|boolean `false` on error otherwise the body of the job.
	 */
	public function peek($id) {
		if (!$this->_write(sprintf('peek %d', $id))) return false;
		return $this->_peekRead();
	}

	/**
	 * Inspect the next ready job.
	 *
	 * @return string|boolean `false` on error otherwise the body of the job.
	 */
	public function peekReady() {
		if (!$this->_write('peek-ready')) return false;
		return $this->_peekRead();
	}

	/**
	 * Inspect the job with the shortest delay left.
	 *
	 * @return string|boolean `false` on error otherwise the body of the job.
	 */
	public function peekDelayed() {
		if (!$this->_write('peek-delayed')) return false;
		return $this->_peekRead();
	}

	/**
	 * Inspect the next job in the list of buried jobs.
	 *
	 * @return string|boolean `false` on error otherwise the body of the job.
	 */
	public function peekBuried() {
		if (!$this->_write('peek-buried')) return false;
		return $this->_peekRead();
	}

	/**
	 * Handles response for all peek methods.
	 *
	 * @return string|boolean `false` on error otherwise the body of the job.
	 */
	protected function _peekRead() {
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'FOUND':
				return array(
					'id' => (integer)strtok(' '),
					'body' => $this->_read((integer)strtok(' '))
				);
			case 'NOT_FOUND':
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Moves jobs into the ready queue (applies to the current tube).
	 *
	 * If there are buried jobs those get kicked only otherwise
	 * delayed jobs get kicked.
	 *
	 * @param integer $bound Upper bound on the number of jobs to kick.
	 * @return integer|boolean False on error otherwise number of job kicked.
	 */
	public function kick($bound) {
		if (!$this->_write(sprintf('kick %d', $bound))) return false;
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'KICKED':
				return (integer)strtok(' ');
			default:
				$this->_error($status);
				return false;
		}
	}

	/* Stats Commands */

	/**
	 * Gives statistical information about the specified job if it exists.
	 *
	 * @param integer $id The job id
	 * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary
	 */
	public function statsJob($id) {
		if (!$this->_write(sprintf('stats-job %d', $id))) return false;
		return $this->_statsRead();
	}

	/**
	 * Gives statistical information about the specified tube if it exists.
	 *
	 * @param string $tube Name of the tube.
	 * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary.
	 */
	public function statsTube($tube) {
		if (!$this->_write(sprintf('stats-tube %s', $tube))) return false;
		return $this->_statsRead();
	}

	/**
	 * Gives statistical information about the system as a whole.
	 *
	 * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary.
	 */
	public function stats() {
		if (!$this->_write('stats')) return false;
		return $this->_statsRead();
	}

	/**
	 * Returns a list of all existing tubes.
	 *
	 * @return string|boolean `false` on error otherwise a string with a yaml formatted list.
	 */
	public function listTubes() {
		if (!$this->_write('list-tubes')) return false;
		return $this->_statsRead();
	}

	/**
	 * Returns the tube currently being used by the producer.
	 *
	 * @return string|boolean `false` on error otherwise a string with the name of the tube.
	 */
	public function listTubeUsed() {
		if (!$this->_write('list-tube-used')) return false;
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'USING':
				return strtok(' ');
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Alias for listTubeUsed.
	 *
	 * @see Socket_Beanstalk::listTubeUsed()
	 * @return string|boolean `false` on error otherwise a string with the name of the tube.
	 */
	public function listTubeChosen() {
		return $this->listTubeUsed();
	}

	/**
	 * Returns a list of tubes currently being watched by the worker.
	 *
	 * @return string|boolean `false` on error otherwise a string with a yaml formatted list.
	 */
	public function listTubesWatched() {
		if (!$this->_write('list-tubes-watched')) return false;
		return $this->_statsRead();
	}

	/**
	 * Handles responses for all stat methods.
	 *
	 * @param boolean $decode Whether to decode data before returning it or not. Default is `true`.
	 * @return array|string|boolean `false` on error otherwise statistical data.
	 */
	protected function _statsRead($decode = true) {
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'OK':
				$data = $this->_read((integer)strtok(' '));
				return $decode ? $this->_decode($data) : $data;
			default:
				$this->_error($status);
				return false;
		}
	}

	/**
	 * Decodes YAML data. This is a super naive decoder which just works on a
	 * subset of YAML which is commonly returned by beanstalk.
	 *
	 * @param string $data The data in YAML format, can be either a list or a dictionary.
	 * @return array An (associative) array of the converted data.
	 */
	protected function _decode($data) {
		$data = array_slice(explode("\n", $data), 1);
		$result = array();

		foreach ($data as $key => $value) {
			if ($value[0] === '-') {
				$value = ltrim($value, '- ');
			} elseif (strpos($value, ':') !== false) {
				list($key, $value) = explode(':', $value);
				$value = ltrim($value, ' ');
			}
			if (is_numeric($value)) {
				$value = (integer) $value == $value ? (integer) $value : (float) $value;
			}
			$result[$key] = $value;
		}
		return $result;
	}
}

?>
