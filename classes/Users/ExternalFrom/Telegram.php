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
			if ($dataString && Telegram::verifyData($appId, $dataString, false)) {
				if (is_string($dataString)) {
					parse_str($dataString, $data);
				} else {
					$data = $dataString;
				}
				Telegram::$user = Q::json_decode($data['user'], true);

				// -------------------------------------------------------------
				// Detect Telegram "start_param" and complete corresponding intent
				// -------------------------------------------------------------
				if (!empty($data['start_param']) && Q::startsWith($data['start_param'], 'intent-')) {
					$token = substr($data['start_param'], strlen('intent-'));
					if ($token) {
						$intent = new Users_Intent(array('token' => $token));
						if ($intent->retrieve() && empty($intent->completedTime)) {
							// Retrieve target session
							if (!empty($intent->sessionId)) {
								// Start the target session temporarily (no cookie)
								Q_Session::start(false, $intent->sessionId, 'internal', array(
									'temporary' => true
								));
							}

							// Associate Telegram user with the logged-in user
							$external = new Users_ExternalFrom_Telegram();
							$external->platform = 'telegram';
							$external->appId = $appId;
							$external->xid = Q::ifset(Telegram::$user, 'id', null);
							$external->accessToken = null;
							$external->expires = null;

							// If there's a logged-in user in this session, attach
							$user = Users::loggedInUser();
							if ($user) {
								$intent->userId = $user->id;
							}

							// Mark intent as complete
							$intent->completedTime = Q::timestamp();
							$intent->save();

							// Fire event like dispatcher-based completion
							Q::event('Users/intent/telegram/authenticate', array(
								'intent' => $intent,
								'fields' => array(
									'token' => $token,
									'platform' => 'telegram',
									'payload' => $dataString,
									'xid' => Q::ifset(Telegram::$user, 'id', null)
								),
								'sessionId' => $intent->sessionId
							));

							// Persist logged-in user in that target session
							if ($user) {
								Users::setLoggedInUser($user);
							}

							// Optional: after event hook
							Q::event('Users/intent/telegram/completed', array(
								'intent' => $intent,
								'user' => isset($user) ? $user : null,
								'platform' => 'telegram',
								'appId' => $appId
							), 'after');
						}
					}
				}
				// -------------------------------------------------------------
			} else if ($cookie = Q::ifset($_COOKIE, "tgsr_$appId", '')) {
				$decoded = Q::json_decode($cookie, true);
				if (is_array($decoded) && !empty($decoded['id'])) {
					Telegram::$user = $decoded;
				}
			}
			if (!Telegram::$user) {
				return null;
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
		if (empty(Telegram::$user) or empty($this->xid) || empty($this->appId)) {
			return array();
		}
		$result = Telegram::import(Telegram::$user, $fieldNames);
		Users::$cache['platformUserData'] = array('telegram' => $result);
		return $result;
	}
}
