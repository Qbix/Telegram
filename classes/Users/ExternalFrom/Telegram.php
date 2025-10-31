<?php

/**
 * @module Users
 */

/**
 * Class representing Telegram user via a bot
 *
 * @class Users_ExternalFrom_Telegram
 * @extends Users_ExternalFrom
 */
class Users_ExternalFrom_Telegram extends Users_ExternalFrom implements Users_ExternalFrom_Interface
{
	/**
	 * Gets a Users_ExternalFrom_Telegram object constructed from request and/or cookies.
	 * It is your job to populate it with a user id and save it.
	 * @method authenticate
	 * @static
	 * @param {string} [$appId=Q::app()] Can either be an internal appId or a Telegram appId.
	 * @param {boolean} [$setCookie=true] Whether to set tgsr_$appId cookie
	 * @param {boolean} [$longLived=true] Get a long-lived access token, if necessary
	 * @return {Users_ExternalFrom_Telegram|null}
	 *  May return null if no such user is authenticated.
	 */
	static function authenticate($appId = null, $setCookie = true, $longLived = true)
	{
		$telegramUser = null;

		// Case 1: Webhook (bot) update
		if (!empty(Telegram_Dispatcher::$update['message']['from']['id'])) {
			$telegramUser = Telegram_Dispatcher::$update['message']['from'];
		}
		// Case 2: Telegram WebApp signed initData
		else {
			$dataString = Q_Request::special('Users.authPayload.telegram', null);

			if ($dataString && Telegram::verifyData($dataString, false)) {
				if (is_string($dataString)) {
					parse_str($dataString, $data);
				} else {
					$data = $dataString;
				}
				$telegramUser = $data;
			}
			// Case 3: fallback to tgsr_* cookie if present
			else if ($cookie = Q::ifset($_COOKIE, "tgsr_$appId", '')) {
				$decoded = Q::json_decode($cookie, true);
				if (is_array($decoded) && !empty($decoded['id'])) {
					$telegramUser = $decoded;
				}
			}

			// still nothing
			if (!$telegramUser) {
				return null;
			}
		}

		if (empty($telegramUser['id'])) {
			return null;
		}

		$xid = $telegramUser['id'];
		list($appId, $appInfo) = Users::appInfo('telegram', $appId);

		// Enforce minimum account age
		if ($minAge = Q::ifset($appInfo, 'authentication', 'minAgeInDays', 0)) {
			$date = Telegram::approximateRegistrationDate($xid);
			$daystampNow = Q_Daystamp::fromTimestamp(time());
			$daystampRegistered = Q_Daystamp::fromDateTime($date);
			if (($daystampNow - $daystampRegistered) < $minAge) {
				throw new Users_Exception_AccountTooYoung();
			}
		}

		// Set cookies (server-trusted, short-term cache)
		if ($setCookie) {
			$cookieNames = array("tgsr_$appId", "tgsr_{$appId}_expires");
			$expires = time() + 86400; // 1 day or use auth_date + 24h

			Q_Response::setCookie($cookieNames[0], Q::json_encode($telegramUser), $expires);
			Q_Response::setCookie($cookieNames[1], $expires, $expires);
		}

		// Build Users_ExternalFrom_Telegram object
		$ef = new Users_ExternalFrom_Telegram();
		$ef->platform = 'telegram';
		$ef->appId = $appId;
		$ef->xid = $xid;
		$ef->accessToken = null;
		$ef->expires = null;

		if (isset($cookieNames)) {
			$ef->set('cookiesToClearOnLogout', $cookieNames);
		}

		return $ef;
	}

	/**
	 * Gets the logged-in user icon urls
	 * @param {array} [$sizes=Q_Image::getSizes('Users/icon')]
	 *  An array of size strings such as "80x80"
	 * @param {string} [$suffix=".png"] Optional suffix to append to the size strings
	 * @return {array|null} Keys are the size strings with optional $suffix and values are the URLs
	 */
	function icon($sizes = null, $suffix = '.png')
	{
		if (empty($this->xid) || empty($this->appId)) {
			return array();
		}
		return Telegram::userIcon($this->appId, $this->xid, $sizes, $suffix);
	}

	/**
	 * Import some fields from the platform. Also fills Users::$cache['platformUserData'].
	 * This checks Q_Config('Users', 'import', 'telegram') for the fields to import,
	 * during Streams_after_Users_User_saveExecute hook.
	 * Note that importing a username may cause a conflict with an existing user,
	 * during Users_User->beforeSave, if Q_Config::get('Users', 'username', 'unique', true)
	 * in which case the username will be set to null instead.
	 * @param {array} $fieldNames
	 * @return {array}
	 */
	function import($fieldNames = null)
	{
		$result = Telegram::import(Telegram_Dispatcher::$update['message']['from'], $fieldNames);
		Users::$cache['platformUserData'] = array('telegram' => $result);
		return $result;
	}
}