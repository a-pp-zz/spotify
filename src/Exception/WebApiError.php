<?php
namespace AppZz\Http\Spotify\Exception;

class WebApiError extends \Exception {

	private $description;
	private $too_many_requests = false;
	private $auth_error = false;

	public function __construct ($error = '', $description = '', $code = 0)
	{
		$this->description = $description;

		switch ($code) {
			case 401:
			case 403:
				$this->auth_error = true;
				break;
			case 429:
				$this->too_many_requests = true;
				break;
		}

		parent::__construct($error, $code);
	}

	public function getDescription ()
	{
		return $this->description;
	}

	public function isAuthError ()
	{
		return $this->auth_error;
	}

	public function isTooManyRequest ()
	{
		return $this->too_many_requests;
	}
}
