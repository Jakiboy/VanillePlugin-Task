<?php
/**
 * @author    : JIHAD SINNAOUR
 * @package   : VanillePluginTask
 * @version   : 0.1.0
 * @copyright : (c) 2018 - 2021 JIHAD SINNAOUR <mail@jihadsinnaour.com>
 * @link      : https://jakiboy.github.io/VanillePluginTask/
 * @license   : MIT
 *
 * This file if a part of VanillePluginTask Framework
 */

namespace VanillePluginTask\lib;

use VanillePlugin\inc\System;

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
	 * Initiate new background process
	 *
	 * @param void
	 */
	public function __construct()
	{
		parent::__construct();
		$this->cronActionId = "{$this->id}-cron";
		$this->cronIntervalId = "{$this->id}-cron-interval";
		$this->addAction($this->cronActionId, [$this,'handleCronHealthcheck']);
		$this->addFilter('cron_schedules', [$this,'scheduleCronHealthcheck']);
	}

	/**
	 * Dispatch
	 *
	 * @access public
	 * @return void
	 */
	public function dispatch()
	{
		// Schedule the cron healthcheck
		$this->scheduleEvent();

		// Perform remote post
		return parent::dispatch();
	}

	/**
	 * Push to queue
	 *
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
	 * @param string $key
	 * @return object
	 */
	public function delete($key)
	{
		$this->removeOption($key);
		return $this;
	}

	/**
	 * Generate key
	 *
	 * @param int $length
	 * @return string
	 */
	protected function generateKey($length = 64)
	{
		$unique = md5(microtime().rand());
		return substr("{$this->id}_batch_{$unique}", 0, $length);
	}

	/**
	 * Maybe process queue
	 *
	 * @param void
	 * @return void
	 */
	public function maybeHandle()
	{
		// Prevent other requests while processing
		$this->closeSession();

		// Check
		if ( $this->isProcessRunning() || $this->isQueueEmpty() ) {
			die();
		}

		// Security
		$this->checkAjaxReferer($this->id,'nonce');

		// Handle request
		$this->handle();
		die();
	}

	/**
	 * Is queue empty
	 *
	 * @param void
	 * @return bool
	 */
	protected function isQueueEmpty()
	{
		global $wpdb;
		$table  = $wpdb->options;
		$column = 'option_name';

		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}
		$key = $this->id . '_batch_%';
		$count = $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(*)
			FROM {$table}
			WHERE {$column} LIKE %s
		", $key ) );
		return !($count > 0);
	}

	/**
	 * Is process running
	 *
	 * @param void
	 * @return void
	 */
	protected function isProcessRunning()
	{
		if ( $this->getTransient("{$this->id}-process-lock" )) {
			return true;
		}
		return false;
	}

	/**
	 * Lock process
	 *
	 * @param void
	 * @return void
	 */
	protected function lockProcess()
	{
		$this->startTime = time();
		$duration = $this->applyFilter("{$this->id}-default-lock-duration", 20);
		$this->setTransient("{$this->id}-process-lock", microtime(), $duration);
	}

	/**
	 * Unlock process
	 *
	 * @param void
	 * @return object
	 */
	protected function unlockProcess()
	{
		$this->deleteTransient("{$this->id}-process-lock");
		return $this;
	}

	/**
	 * Get batch
	 *
	 * @param void
	 * @return stdClass Return the first batch from the queue
	 */
	protected function getBatch()
	{
		global $wpdb;

		$table        = $wpdb->options;
		$column       = 'option_name';
		$key_column   = 'option_id';
		$value_column = 'option_value';

		if ( is_multisite() ) {
			$table        = $wpdb->sitemeta;
			$column       = 'meta_key';
			$key_column   = 'meta_id';
			$value_column = 'meta_value';
		}

		$key = $this->id . '_batch_%';

		$query = $wpdb->get_row( $wpdb->prepare( "
			SELECT *
			FROM {$table}
			WHERE {$column} LIKE %s
			ORDER BY {$key_column} ASC
			LIMIT 1
		", $key ) );

		$batch       = new \stdClass();
		$batch->key  = $query->$column;
		$batch->data = maybe_unserialize( $query->$value_column );

		return $batch;
	}

	/**
	 * Handle
	 *
	 * @param void
	 * @return void
	 * Pass each queue item to the task handler, while remaining
	 * within server memory and time limit constraints.
	 */
	protected function handle()
	{
		$this->lockProcess();
		do {
			$batch = $this->getBatch();
			foreach ( $batch->data as $key => $value ) {
				$task = $this->task( $value );
				if ( false !== $task ) {
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
		} while ( !$this->isTimeOut() && !System::isMemoryOut() && !$this->isQueueEmpty() );
		$this->unlockProcess();
		// Start next batch or complete process
		if ( ! $this->isQueueEmpty() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}
		die();
	}

	/**
	 * Time out
	 *
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
	 * @param void
	 * @return void
	 */
	protected function complete()
	{
		$this->clearScheduledEvent();
	}

	/**
	 * Schedule cron healthcheck
	 *
	 * @access public
	 * @param mixed $schedules
	 * @return mixed
	 */
	public function scheduleCronHealthcheck($schedules)
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
	 * Handle cron healthcheck
	 *
	 * @param void
	 * @return void
	 */
	public function handleCronHealthcheck()
	{
		if ( $this->isProcessRunning() ) {
			exit;
		}
		if ( $this->isQueueEmpty() ) {
			$this->clearScheduledEvent();
			exit;
		}
		$this->handle();
		exit;
	}

	/**
	 * Schedule event
	 *
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
	 * Cancel Process
	 *
	 * @param void
	 * @return void
	 */
	public function cancelProcess()
	{
		if ( !$this->isQueueEmpty() ) {
			$batch = $this->getBatch();
			$this->delete($batch->key);
			wp_clear_scheduled_hook($this->cronActionId);
		}
	}

	/**
	 * Perform task
	 *
	 * @param mixed $item
	 * @return mixed
	 */
	abstract protected function task($item);
}
