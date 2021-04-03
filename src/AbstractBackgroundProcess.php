<?php
/**
 * @author    : JIHAD SINNAOUR
 * @package   : VanillePluginTask
 * @version   : 0.1.3
 * @copyright : (c) 2018 - 2021 JIHAD SINNAOUR <mail@jihadsinnaour.com>
 * @link      : https://jakiboy.github.io/VanillePluginTask/
 * @license   : MIT
 *
 * This file if a part of VanillePluginTask
 * Cloned from deliciousbrains/wp-background-processing
 */

namespace VanillePluginTask;

use VanillePlugin\inc\System;
use VanillePlugin\inc\Stringify;

abstract class AbstractBackgroundProcess extends AbstractAsyncRequest
{
	/**
	 * @var string $action
	 * @var int $startTime
	 * @var mixed $cronActionId
	 * @var mixed $cronIntervalId
	 * @var int $cronInterval
	 * @access protected
	 */
	protected $action = 'background-process';
	protected $startTime = 0;
	protected $cronActionId;
	protected $cronIntervalId;
	protected $cronInterval;

	/**
	 * Init new background process
	 *
	 * @param void
	 */
	public function __construct()
	{
		parent::__construct();
		$this->cronActionId = "{$this->id}-cron";
		$this->cronIntervalId = "{$this->id}-cron-interval";
		$this->addAction($this->cronActionId, [$this,'handleCron']);
		$this->addFilter('cron_schedules', [$this,'scheduleCron']);
	}

	/**
	 * Dispatch process
	 *
	 * @access public
	 * @param void
	 * @return void
	 */
	public function dispatch()
	{
		// Schedule the cron event
		$this->scheduleEvent();

		// Perform remote post
		return parent::dispatch();
	}

	/**
	 * Push data to queue
	 *
	 * @access public
	 * @param mixed $data
	 * @return object AbstractBackgroundProcess
	 */
	public function pushToQueue($data)
	{
		$this->data[] = $data;
		return $this;
	}

	/**
	 * Save queue
	 *
	 * @access public
	 * @param void
	 * @return object
	 */
	public function save()
	{
		$key = $this->generateKey();
		if ( !empty($this->data) ) {
			$this->updateOption($key,$this->data);
		}
		return $this;
	}

	/**
	 * Update queue
	 *
	 * @access public
	 * @param string $key
	 * @param array $data
	 * @return object
	 */
	public function update($key, $data)
	{
		if ( !empty($data) ) {
			$this->updateOption($key,$data);
		}
		return $this;
	}

	/**
	 * Delete queue
	 *
	 * @access public
	 * @param string $key
	 * @return object
	 */
	public function delete($key)
	{
		$this->removeOption($key);
		return $this;
	}

	/**
	 * Cancel process
	 *
	 * @access public
	 * @param void
	 * @return void
	 */
	public function cancel()
	{
		if ( !$this->isEmptyQueue() ) {
			$batch = $this->getBatch();
			$this->delete($batch->key);
			wp_clear_scheduled_hook($this->cronActionId);
		}
	}

	/**
	 * Maybe process queue
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	public function maybeHandle()
	{
		// Prevent other requests while processing
		$this->closeSession();

		// Check
		if ( $this->isRunning() || $this->isEmptyQueue() ) {
			die();
		}

		// Security
		$this->checkAjaxReferer($this->id,'nonce');

		// Handle request
		$this->handle();
		die();
	}

	/**
	 * Schedule cron
	 *
	 * @access public
	 * @param mixed $schedules
	 * @return mixed
	 */
	public function scheduleCron($schedules)
	{
		$interval = $this->applyFilter("{$this->id}-cron-interval", 5);
		if ( $this->cronInterval ) {
			$interval = $this->applyFilter("{$this->id}-cron-interval", $this->cronInterval);
		}
		$schedules["{$this->id}-cron-interval"] = [
			'interval' => MINUTE_IN_SECONDS * $interval,
			'display'  => sprintf( __('Every %d minutes'), $interval)
		];
		return $schedules;
	}

	/**
	 * Handle cron
	 *
	 * @access public
	 * @param void
	 * @return void
	 */
	public function handleCron()
	{
		if ( $this->isRunning() ) {
			exit;
		}
		if ( $this->isEmptyQueue() ) {
			$this->clearScheduledEvent();
			exit;
		}
		$this->handle();
		exit;
	}

	/**
	 * Generate batch key
	 *
	 * @access protected
	 * @param int $length
	 * @return string
	 */
	protected function generateKey($length = 64)
	{
		$unique = md5(microtime().rand());
		return substr("{$this->id}-batch-{$unique}",0,$length);
	}

	/**
	 * Is queue empty
	 *
	 * @access protected
	 * @param void
	 * @return bool
	 */
	protected function isEmptyQueue()
	{
		$key = "{$this->id}-batch-%";
		$sql = "SELECT COUNT(*) FROM `{$this->db->options}` WHERE `option_name` LIKE %s;";
		$count = $this->db->get_var($this->db->prepare($sql,$key));
		return !($count > 0);
	}

	/**
	 * Is process running
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function isRunning()
	{
		if ( $this->getTransient("{$this->id}-process-lock") ) {
			return true;
		}
		return false;
	}

	/**
	 * Lock process
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function lock()
	{
		$this->startTime = time();
		$duration = $this->applyFilter("{$this->id}-default-lock-duration", 20);
		$this->setTransient("{$this->id}-process-lock", microtime(), $duration);
	}

	/**
	 * Unlock process
	 *
	 * @access protected
	 * @param void
	 * @return object
	 */
	protected function unlock()
	{
		$this->deleteTransient("{$this->id}-process-lock");
		return $this;
	}

	/**
	 * Get batch
	 *
	 * @access protected
	 * @param void
	 * @return object
	 */
	protected function getBatch()
	{
		$key = "{$this->id}-batch-%";
		$sql = "SELECT * FROM `{$this->db->options}` WHERE `option_name` LIKE %s ORDER BY `option_id` ASC LIMIT 1;";
		$query = $this->db->get_row($this->db->prepare($sql,$key));
		$batch = new \stdClass();
		$batch->key = $query->option_name;
		$batch->data = Stringify::unserialize($query->option_value);
		return $batch;
	}

	/**
	 * Handle process
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function handle()
	{
		// Lock process
		$this->lock();

		// Loop for process
		do {
			$batch = $this->getBatch();
			foreach ( $batch->data as $key => $value ) {
				$task = $this->task( $value );
				if ( $task !== false ) {
					$batch->data[$key] = $task;
				} else {
					unset($batch->data[$key]);
				}
				if ( $this->isTimeOut() || System::isMemoryOut() ) {
					break;
				}
			}
			// Update or delete current batch
			if ( !empty($batch->data) ) {
				$this->update($batch->key,$batch->data);
			} else {
				$this->delete($batch->key);
			}
		} while ( !$this->isTimeOut() && !System::isMemoryOut() && !$this->isEmptyQueue() );

		// Unlock process
		$this->unlock();

		// Start next batch or complete process
		if ( ! $this->isEmptyQueue() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}
		die();
	}

	/**
	 * Time out
	 *
	 * @access protected
	 * @param void
	 * @return bool
	 */
	protected function isTimeOut()
	{
		$limit = $this->applyFilter("{$this->id}-default-time-limit", 20);
		$finish = $this->startTime + $limit;
		if ( time() >= $finish ) {
			return true;
		}
		return false;
	}

	/**
	 * Complete process
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function complete()
	{
		$this->clearScheduledEvent();
	}

	/**
	 * Schedule event
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function scheduleEvent()
	{
		if ( !wp_next_scheduled($this->cronActionId) ) {
			wp_schedule_event(time(),$this->cronIntervalId,$this->cronActionId);
		}
	}

	/**
	 * Clear scheduled event
	 *
	 * @access protected
	 * @param void
	 * @return void
	 */
	protected function clearScheduledEvent()
	{
		$timestamp = wp_next_scheduled($this->cronActionId);
		if ( $timestamp ) {
			wp_unschedule_event($timestamp,$this->cronActionId);
		}
	}

	/**
	 * Perform task
	 *
	 * @access protected
	 * @param mixed $item
	 * @return mixed
	 */
	abstract protected function task($item);
}
