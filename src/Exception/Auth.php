<?php
namespace AppZz\Http\Spotify\Exception;

class Auth extends \Exception {

	private $description;

	public function __construct ($error = '', $description = '', $code = 0)
	{
		$this->message = $error;
		$this->code = $code;
		$this->description = $description;
	}

	public function getDescription ()
	{
		return $this->description;
	}
}
