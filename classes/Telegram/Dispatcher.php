<?php

/**
 * @module Q
 */
/**
 * This class lets you dispatch telegram updates to be handled
 * @class Telegram_Dispatcher
 */
class Telegram_Dispatcher
{	
	/**
	 * Used to get/set the result of the dispatching
	 * @method result
	 * @static
	 * @param {string} [$new_result=null] Pass a string here to record a result of the dispatching.
	 * @param {boolean} [$overwrite=false] If a result is already set, doesn't override it unless you pass true here.
	 */
	static function result($new_result = null, $overwrite = false)
	{
		static $result = null;
		if (isset($new_result)) {
			if (!isset($result) or $overwrite === true) {
				$result = $new_result;
			}
		}
		return $result;
	}
	
	/**
	 * Dispatches a Telegram Update for internal processing.
	 * Usually called by a front controller.
	 * @method dispatch
	 * @static
	 * @param {array} [$update=array()] This is an update which was posted to a Telegram Web Hook
     *   or came from Telegram_Bot::getUpdates()
	 * @return {boolean}
	 * @throws {Q_Exception_MethodNotSupported}
	 */
	static function dispatch(
		array $update = array()
    ) {
		self::$startedDispatch = true;
		self::$update = $update;

        if (empty($update)) {
            return false;
        }

        $headerValue = Q::ifset($_SERVER, 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN', null);
        if (!$headerValue) {
            throw new Users_Exception_NotAuthorized();
        }

        list($platform, $appId) = explode('-', $headerValue);
        if ($headerValue !== Telegram::secretToken($appId)) {
            throw new Users_Exception_NotAuthorized();
        }

        $updateType = Telegram_Bot::getUpdateType($update);

        $params = compact('appId', 'update', 'updateType');

        try {
            // Perform validation
            if (!isset(self::$skip['Telegram/validate'])) {
                /**
                 * Gives the app a chance to validate the Telegram update and call
                 * Q_Response::addError() zero or more times.
                 * The parameters are the routed array
                 * @event Telegram/validate
                 * @param {array} $update
                 */
                Q::event('Telegram/validate', $params, true);
            }
			// Potentially authenticate the Telegram user
			// and even create a native user account corresponding to them
			$authenticated = ($updateType === 'message')
				? self::doAuthentication($appId, $update)
				: null;
            if (!isset(self::$skip['Telegram/objects'])) {
                /**
                 * Gives the app a chance to fetch objects needed for handling
                 * the update.
                 * @event Telegram/objects
                 * @param {array} $update
                 */
                Q::event('Telegram/objects', $params, true);
            }
            if (!isset(self::$skip['Telegram/action'])) {
                /**
                 * Gives the app a chance to take action regarding an update.
                 * Can potentially change server state.
                 * @event Telegram/action
                 * @param {array} $update
                 */
                Q::event('Telegram/action', $params, true);
            }
			// If we got this far, then this event can be by various plugins
			// to persist various payment & micropayment transactions to the database.
			if (!isset(self::$skip['Q/payments'])) {
				/**
				 * Gives the app a chance to persist payments transactions to the database.
				 * the request.
				 * @event Q/payments
				 * @param {array} $params
				 */
				Q::event('Q/payments', $params, true);
			}
			// Finally, we can generate a response (after actions and payments were processed)
            /**
             * Gives the app a chance to generate a response.
             * You should not change the server state when handling this event.
             * @event Telegram/response
             * @param {array} $update
             */
            Q::event('Telegram/response', $params);
        } catch (Exception $exception) {
			/**
			 * @event Telegram/exception
			 * @param {Exception} exception
			 */
			Q::event('Telegram/exception', @compact('appId', 'update', 'updateType', 'exception')); // the original exception
        }

		self::$servedResponse = false;
        self::result("Served response");
		self::$served = 'response';
		return true;
    }

	/**
	 * Handle authentication as well as the /start command from bot, with a parameter
	 * @method doAuthentication
	 * @static
	 * @param {string} $appId
	 * @param {array} $update
	 * @return {boolean|string} could be true, false, 'connected', 'adopted', 'registered'
	 * @throws {Q_Exception_WrongValue}
	 * @throws {Users_Exception_NotAuthorized}
	 */
	protected static function doAuthentication($appId, $update)
	{
		if (empty($update['message']['from']['id'])) {
			return false;
		}
		if (!Q::startsWith($update['message']['text'], '/start ')) {
			// this is a message but not a /start message,
			// so just authenticate the user and return true
			$deterministicId = Telegram::sessionId(
				$appId, $update['message']['from']['id']
			);
			Q_Session::start(false, $deterministicId, 'internal');
			Users::authenticate('telegram', $appId);
			return true;
		} 
		list($start, $parameter) = explode(' ', $update['message']['text'], 2);
		if (empty($parameter)) {
			return false;
		}
		$parts = explode('-', $parameter, 4);
		if (empty($parts)) {
			throw new Q_Exception_WrongValue(array(
				'field' => 'startCommandParameter',
				'range' => 'intentId',
				'value' => $parameter
			));
		}
		$token = reset($parts);
		$intent = new Users_Intent(compact('token'));
		if (!$intent->retrieve()) {
			throw new Q_Exception_MissingRow(array(
				'table' => 'Users_Intent',
				'criteria' => "token=$token"
			));
		}
		$session = null;
		if (empty($intent->sessionId)) {
			throw new Q_Exception_MissingValue(array(
				'field' => 'sessionId',
				'range' => 'a valid session ID',
				'value' => 'empty'
			));
		}
		$session = new Users_Session();
		$session->id = $intent->sessionId;
		if (!$session->retrieve()) {
			throw new Q_Exception_SessionTerminated();
		}
		$content = json_decode($session->content, true);
		$originalUserId = Q::ifset($content, 'Users', 'loggedInUser', 'id', null);

		// open session in the database with deterministic ID
		$deterministicId = Telegram::sessionId(
			$appId, $update['message']['from']['id']
		);
		Q_Session::start(false, $deterministicId, 'internal');
		
		if ($originalUserId) {
			// if user was logged into session that generated intent,
			// set them as logged-in user here too, before connecting telegram user
			Users::setLoggedInUser($originalUserId, array('keepSessionId' => true)); // set the user as logged in 
		}
		Users::authenticate('telegram', $appId);
		$user = Users::loggedInUser();
		if (!$originalUserId) {
			// set the user as logged-in on the original session, too
			$content['Users']['loggedInUser']['id'] = $user->id;
			$session->content = Q::json_encode($content);
			$session->save(); // note that this is part of an atomic transaction
		}
		return true;
	}

	/**
	 * Set to "response"
	 * @property $served
	 * @type string
	 * @static
	 * @protected
	 */
	public static $served;
    /**
	 * Whether the dispatch method was called since the beginning of the request
	 * @property @startedDispatch
	 * @type boolean
	 * @static
	 * @public
	 */
	public static $startedDispatch = false;
	/**
	 * Whether a response was started, since the beginning of the request
	 * @property $startedResponse
	 * @type boolean
	 * @static
	 * @public
	 */
	public static $startedResponse = false;
	/**
	 * Whether a response was served since the last time dispatch started
	 * @property $servedResponse
	 * @type boolean
	 * @static
	 * @public
	 */
	public static $servedResponse = false;

    /**
	 * @property $skip
	 * @type array
	 * @static
	 * @protected
	 */
	protected static $skip = array();

	/**
	 * @property $update
	 * @type array
	 * @static
	 * @public
	 */
	public static $update;
}
