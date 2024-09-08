<?php

/**
 * @module Q
 */

/**
 * Telegram controller - executes telegram update request
 * @class Telegram_Controller
 */
class Telegram_Controller
{
	/**
	 * Execute telegram update request
	 * @method execute
	 * @static
	 */
	static function execute()
	{	
		// Set the controller that is being used
		if (!isset(Q::$controller)) {
			Q::$controller = 'Telegram_Controller';
		}
		
		try {
			$method = Q_Request::method();
            Q_Request::handleInput();
			Q::log("$method url: " . Q_Request::url(true),
				'telegram', null, array('maxLength' => 10000)
			);
			Telegram_Dispatcher::dispatch($_REQUEST);
			$dispatchResult = Q_Dispatcher::result();
			if (!isset($dispatchResult)) {
				$dispatchResult = 'Ran dispatcher';
			}
            $handled = true;
			if ($handled) {
				Q::log("~" . ceil(Q::milliseconds()) . 'ms+'
					. ceil(memory_get_peak_usage()/1000) . 'kb.'
					. " $dispatchResult",
					'telegram', null, array('maxLength' => 10000)
				);
			} else {
				Q::log("~" . ceil(Q::milliseconds()) . 'ms+'
					. ceil(memory_get_peak_usage()/1000) . 'kb.'
					. " $dispatchResult No route for " . $_SERVER['REQUEST_URI'],
					null, null, array('maxLength' => 10000)
				);
			}
		} catch (Exception $exception) {
			/**
			 * @event Q/exception
			 * @param {Exception} exception
			 */
			Q::event('Q/exception', @compact('exception'));
		}
	}
}
