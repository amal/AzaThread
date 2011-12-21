<?php

/**
 * Class LibEventBuffer
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.libevent
 */
class CLibEventBuffer extends CLibEventBasic
{
	/**
	 * Buffer read error
	 */
	const E_READ	= 0x01; // EVBUFFER_READ

	/**
	 * Buffer write error
	 */
	const E_WRITE	= 0x02; // EVBUFFER_WRITE

	/**
	 * Buffer EOF error
	 */
	const E_EOF		= 0x10; // EVBUFFER_EOF

	/**
	 * Buffer error
	 */
	const E_ERROR	= 0x20; // EVBUFFER_ERROR

	/**
	 * Buffer timeout error
	 */
	const E_TIMEOUT	= 0x40; // EVBUFFER_TIMEOUT


	/**
	 * Default <i>lowmark</i>
	 *
	 * @see setWatermark
	 */
	const DEF_LOWMARK       = 1;

	/**
	 * Default <i>highmark</i>
	 *
	 * @see setWatermark
	 */
	const DEF_HIGHMARK      = 0xffffff;

	/**
	 * Default priority
	 *
	 * @see setPriority
	 */
	const DEF_PRIORITY      = 10;

	/**
	 * Default read timeout
	 *
	 * @see setTimout
	 */
	const DEF_TIMEOUT_READ  = 30;

	/**
	 * Default write timeout
	 *
	 * @see setTimout
	 */
	const DEF_TIMEOUT_WRITE = 30;


	/**
	 * @var resource
	 */
	public $stream;


	/**
	 * Creates a new buffered event resource.
	 *
	 * @see event_buffer_new
	 *
	 * @throws AzaException
	 *
	 * @param resource $stream <p>
	 * Valid PHP stream resource. Must be castable to file descriptor.
	 * </p>
	 * @param callback|null $readcb <p>
	 * Callback to invoke where there is data to read, or NULL if no callback is desired.
	 * <br><tt>function(resource $buf, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param callback|null $writecb <p>
	 * Callback to invoke where the descriptor is ready for writing, or NULL if no callback is desired.
	 * <br><tt>function(resource $buf, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param callback $errorcb <p>
	 * Callback to invoke where there is an error on the descriptor, cannot be NULL.
	 * <br><tt>function(resource $buf, int $what, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg [optional] <p>
	 * An argument that will be passed to each of the callbacks.
	 * </p>
	 */
	public function __construct($stream, $readcb, $writecb, $errorcb, $arg = null)
	{
		parent::__construct();
		$this->stream = $stream;
		if (!$this->resource = event_buffer_new($stream, $readcb, $writecb, $errorcb, array($this, $arg))) {
			throw new AzaException('Can\'t create new buffered event resourse (event_buffer_new)', 1);
		}
	}


	/**
	 * Disables buffered event
	 *
	 * @see event_buffer_disable
	 *
	 * @throws AzaException
	 *
	 * @param int $events Any combination of EV_READ and EV_WRITE.
	 *
	 * @return CLibEventBuffer
	 */
	public function disable($events)
	{
		$this->checkResourse();
		if (!event_buffer_disable($this->resource, $events)) {
			throw new AzaException("Can't disable buffered event (event_buffer_disable)", 1);
		}
		return $this;
	}

	/**
	 * Enables buffered event
	 *
	 * @see event_buffer_enable
	 *
	 * @throws AzaException
	 *
	 * @param int $events Any combination of EV_READ and EV_WRITE.
	 *
	 * @return CLibEventBuffer
	 */
	public function enable($events)
	{
		$this->checkResourse();
		if (!event_buffer_enable($this->resource, $events)) {
			throw new AzaException("Can't enable buffered event (event_buffer_enable)", 1);
		}
		return $this;
	}


	/**
	 * Associate event with an event base
	 *
	 * @see event_buffer_base_set
	 *
	 * @throws AzaException
	 *
	 * @param CLibEventBase $event_base
	 *
	 * @return CLibEventBuffer
	 */
	public function setBase($event_base)
	{
		$this->checkResourse();
		$event_base->checkResourse();
		if (!event_buffer_base_set($this->resource, $event_base->resource)) {
			throw new AzaException('Can\'t set buffered event base (event_buffer_base_set)', 1);
		}
		return parent::setBase($event_base);
	}

	/**
	 * Destroys the buffered event and frees all the resources associated.
	 *
	 * @see event_buffer_free
	 *
	 * @throws AzaException
	 *
	 * @return CLibEventBuffer
	 */
	public function free()
	{
		if ($this->resource) {
			event_buffer_free($this->resource);
			$this->resource = null;
			parent::free();
		}
		return $this;
	}


	/**
	 * Reads data from the input buffer of the buffered event.
	 *
	 * @see event_buffer_read
	 *
	 * @param int $data_size Data size in bytes.
	 *
	 * @return string|bool Data from buffer or FALSE
	 */
	public function read($data_size)
	{
		$this->checkResourse();
		return event_buffer_read($this->resource, $data_size);
	}

	/**
	 * Writes data to the specified buffered event.
	 *
	 * @see event_buffer_write
	 *
	 * @throws AzaException
	 *
	 * @param string $data      The data to be written.
	 * @param int    $data_size Optional size parameter. Writes all the data by default
	 *
	 * @return CLibEventBuffer
	 */
	public function write($data, $data_size = -1)
	{
		$this->checkResourse();
		if (!event_buffer_write($this->resource, $data, $data_size)) {
			throw new AzaException('Can\'t write data to the buffered event (event_buffer_write)', 1);
		}
		return $this;
	}


	/**
	 * Changes the stream on which the buffered event operates.
	 *
	 * @see event_buffer_fd_set
	 *
	 * @throws AzaException
	 *
	 * @param resource $stream Valid PHP stream, must be castable to file descriptor.
	 *
	 * @return CLibEventBuffer
	 */
	public function setStream($stream)
	{
		$this->checkResourse();
		if (!event_buffer_fd_set($this->resource, $stream)) {
			throw new AzaException("Can't set buffered event stream (event_buffer_fd_set)", 1);
		}
		$this->stream = $stream;
		return $this;
	}

	/**
	 * Sets or changes existing callbacks for the buffered event.
	 *
	 * @see event_buffer_set_callback
	 *
	 * @throws AzaException
	 *
	 * @param callback|null $readcb <p>
	 * Callback to invoke where there is data to read, or NULL if no callback is desired.
	 * <br><tt>function(resource $buf, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param callback|null $writecb <p>
	 * Callback to invoke where the descriptor is ready for writing, or NULL if no callback is desired.
	 * <br><tt>function(resource $buf, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param callback $errorcb <p>
	 * Callback to invoke where there is an error on the descriptor, cannot be NULL.
	 * <br><tt>function(resource $buf, int $what, array $args(CLibEventBuffer $e, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg [optional] <p>
	 * An argument that will be passed to each of the callbacks.
	 * </p>
	 *
	 * @return CLibEventBuffer
	 */
	public function setCallback($readcb, $writecb, $errorcb, $arg = null)
	{
		$this->checkResourse();
		if (!event_buffer_set_callback($this->resource, $readcb, $writecb, $errorcb, array($this, $arg))) {
			throw new AzaException("Can't set buffered event callbacks (event_buffer_set_callback)", 1);
		}
		return $this;
	}


	/**
	 * Sets the read and write timeouts for the specified buffered event.
	 *
	 * @see event_buffer_timeout_set
	 *
	 * @throws AzaException
	 *
	 * @param int $read_timeout  Read timeout (in seconds).
	 * @param int $write_timeout Write timeout (in seconds).
	 *
	 * @return CLibEventBuffer
	 */
	public function setTimout($read_timeout = self::DEF_TIMEOUT_READ, $write_timeout = self::DEF_TIMEOUT_WRITE)
	{
		$this->checkResourse();
		event_buffer_timeout_set($this->resource, $read_timeout, $write_timeout);
		return $this;
	}

	/**
	 * Set the marks for read and write events.
	 *
	 * <p>Libevent does not invoke read callback unless there is at least <i>lowmark</i>
	 * bytes in the input buffer; if the read buffer is beyond the <i>highmark</i>,
	 * reading is stopped. On output, the write callback is invoked whenever
	 * the buffered data falls below the <i>lowmark</i>.</p>
	 *
	 * @see event_buffer_timeout_set
	 *
	 * @throws AzaException
	 *
	 * @param int $events   Any combination of EV_READ and EV_WRITE.
	 * @param int $lowmark  Low watermark.
	 * @param int $highmark High watermark.
	 *
	 * @return CLibEventBuffer
	 */
	public function setWatermark($events, $lowmark = self::DEF_LOWMARK, $highmark = self::DEF_HIGHMARK)
	{
		$this->checkResourse();
		event_buffer_watermark_set($this->resource, $events, $lowmark, $highmark);
		return $this;
	}

	/**
	 * Assign a priority to a buffered event.
	 *
	 * @see event_buffer_priority_set
	 *
	 * @param int $value <p>
	 * Priority level. Cannot be less than zero and cannot exceed
	 * maximum priority level of the event base (see {@link event_base_priority_init}()).
	 * </p>
	 *
	 * @return CLibEventBuffer
	 */
	public function setPriority($value = self::DEF_PRIORITY)
	{
		$this->checkResourse();
		if (!event_buffer_priority_set($this->resource, $value)) {
			throw new AzaException("Can't set buffered event priority to $value (event_buffer_priority_set)", 1);
		}
		return $this;
	}
}
