<?php

/**
 * @module Telegram
 */
class Telegram_Exception_MissingToken extends Q_Exception
{
	/**
	 * An exception is raised if the request is missing the token in the parameters.
	 * @class Telegram_Exception_MissingToken
	 * @constructor
	 * @extends Q_Exception
	 */
};

Q_Exception::add('Telegram_Exception_MissingToken', 'Missing Token');
