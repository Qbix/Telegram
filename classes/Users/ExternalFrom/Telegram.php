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
		if (empty(Telegram::$user['id'])) {
			$dataString = Q_Request::special('Users.authPayload.telegram', null);
			if ($dataString && Telegram::verifyData($appId, $dataString, true)) {
				// try to get user from auth payload
				if (is_string($dataString)) {
					parse_str($dataString, $data);
				} else {
					$data = $dataString;
				}
				Telegram::$user = Q::json_decode($data['user'], true);
				if (empty(Telegram::$startParam)) {
					// try to get start param from auth payload
					if (!empty($data['start_param']) && Q::startsWith($data['start_param'], 'intent-')) {
						Telegram::$startParam = $data['start_param'];
					}
				}
			} else if ($cookie = Q::ifset($_COOKIE, "tgsr_$appId", '')) {
				// try to get user from cookie set previously
				$decoded = Q::json_decode($cookie, true);
				if (is_array($decoded) && !empty($decoded['id'])) {
					Telegram::$user = $decoded;
				}
			}
			if (empty(Telegram::$user['id'])) {
				return null;
			}
		}

		// open session in the database with deterministic ID,
		// which is based on the Telegram user ID, but only
		// after verifying the authenticity of the user data,
		// either from a securely signed payload / cookie,
		// or from Telegram Bot API update containing Telegram::secretToken().
		// Thus, it is safe to generate a deterministic session ID
		// to be used with Telegram Bots and Mini-App WebViews.
		$deterministicId = Telegram::sessionId(
			$appId, Telegram::$user['id']
		);
		Q_Session::start(false, $deterministicId, 'internal');

		if (Telegram::$startParam) {
			$parts = explode('-', Telegram::$startParam, 4);
			if (count($parts) < 2) {
				throw new Q_Exception_WrongValue(array(
					'field' => 'startCommandParameter',
					'range' => 'intent-$token or invite-$token',
					'value' => Telegram::$startParam
				));
			}
			$type = $parts[0];
			$token = $parts[1];
			if ($type === 'intent') {
				if (Users::$intent = Users_Intent::fromToken($token)) {
					Users::$intent->accept(); // may set logged-in user from original session
					// authenticate user from intent
					$content = Users::$intent->get('sessionContent', null);
					if ($invite = Q::ifset($content, 'Streams', 'invite', null)) {
						// set invite in session for later processing
						$_SESSION['Streams']['invite'] = $invite;
					}
				}				
			} else if ($type === 'invite') {
				if ($invite = Streams_Invite::fromToken($token)) {
					// accept invite and autosubscribe if first time and possible
					$invite->accept(array(
						'access' => true,
						'subscribe' => true
					));
					$_SESSION['Streams']['invite'] = $invite->fields;
				}
			}
		}

		if (empty(Telegram::$user['id'])) {
			return null;
		}

		$xid = Telegram::$user['id'];
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

			Q_Response::setCookie($cookieNames[0], Q::json_encode(Telegram::$user), $expires);
			Q_Response::setCookie($cookieNames[1], $expires, $expires);
		}

		$platform = 'telegram';
		Users::$cache['platformUserData'] = array(
            'telegram' => Q::take(Telegram::$user, Q_Config::get('Users', 'import', 'telegram', array(
				'username', 'first_name', 'last_name', 'bio'
			)))
        );

		// Build Users_ExternalFrom_Telegram object
		$ef = new Users_ExternalFrom_Telegram();
		$ef->platform = 'telegram';
		$ef->appId = $appId;
		$ef->xid = $xid;
		$ef->accessToken = null;
		$ef->expires = null;
		$ef->set('intent', Users::$intent);
		$ef->set('results', array());

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
		if (empty(Telegram::$user) or empty($this->xid) || empty($this->appId)) {
			return array();
		}
		$result = Telegram::import(Telegram::$user, $fieldNames);
		Users::$cache['platformUserData'] = array('telegram' => $result);
		return $result;
	}
}
