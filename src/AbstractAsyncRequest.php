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

use VanillePlugin\lib\PluginOptions;
use VanillePlugin\lib\Request;
use VanillePlugin\inc\Stringify;
use VanillePlugin\inc\Server;

abstract class AbstractAsyncRequest extends PluginOptions
{
	/**
	 * @access protected
	 * @var string $action
	 * @var string $id
	 * @var array $data
	 */
	protected $action = 'async-request';
	protected $id;
	protected $data = [];

	/**
	 * Initiate new async request
	 *
	 * @param void
	 */
	public function __construct()
	{
		$this->id = "{$this->getNameSpace()}-{$this->action}";
		$this->addAction("wp_ajax_{$this->id}", [$this,'maybeHandle']);
		$this->addAction("wp_ajax_nopriv_{$this->id}", [$this,'maybeHandle']);
	}

	/**
	 * Set data used during the request
	 *
	 * @param array $data
	 * @return object AbstractAsyncRequest
	 */
	public function setData($data)
	{
		$this->data = $data;
		return $this;
	}

	/**
	 * Dispatch the async request
	 *
	 * @param void
	 * @return array|object
	 */
	public function dispatch()
	{
		$url = Request::addQueryArg($this->getQueryArgs(),$this->getQueryUrl());
		$req = new Request();
		$res = $req->post(Stringify::escapeUrl($url),$this->getPostArgs());
		return $res->getBody();
	}

	/**
	 * Get request query args
	 *
	 * @param void
	 * @return array
	 */
	protected function getQueryArgs()
	{
		return [
			'action' => $this->id,
			'nonce'  => $this->createNonce($this->id)
		];
	}

	/**
	 * Close session
	 *
	 * @param void
	 * @return void
	 */
	protected function closeSession()
	{
		session_write_close();
	}

	/**
	 * Get request query URL
	 *
	 * @param void
	 * @return string
	 */
	protected function getQueryUrl()
	{
		return $this->getAdminUrl('admin-ajax.php');
	}

	/**
	 * Get post request args
	 *
	 * @param void
	 * @return array
	 */
	protected function getPostArgs()
	{
		return [
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE,
			'sslverify' => Server::isHttps()
		];
	}

	/**
	 * Check for correct nonce and pass to handler
	 *
	 * @param void
	 * @return void
	 */
	public function maybeHandle()
	{
		// Prevent other requests while processing
		$this->closeSession();

		// Security
		$this->checkAjaxReferer($this->id,'nonce');

		// Handle request
		$this->handle();
		die();
	}

	/**
	 * Handle request
	 *
	 * @param void
	 * @return mixed
	 */
	abstract protected function handle();
}
