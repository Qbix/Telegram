<?php

/**
 * @module Telegram
 */
class Telegram_Exception_API extends Q_Exception
{
	/**
	 * An exception is raised if the request is missing the token in the parameters.
	 * @class Telegram_Exception_API
	 * @constructor
	 * @extends Q_Exception
     * @param {string} error_code
     * @param {string} description
	 */
};

Q_Exception::add('Telegram_Exception_API', 'Telegram API error {{error_code}}: {{description}}');
