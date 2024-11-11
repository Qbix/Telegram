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
	 * Gets a Users_ExternalFrom_Telegra  object constructed from request and/or cookies.
	 * It is your job to populate it with a user id and save it.
	 * @method authenticate
	 * @static
	 * @param {string} [$appId=Q::app()] Can either be an interal appId or an Telegram appId.
	 * @param {boolean} [$setCookie=true] Whether to set fbsr_$appId cookie
	 * @param {boolean} [$longLived=true] Get a long-lived access token, if necessary
	 * @return {Users_ExternalFrom_Telegram|null}
	 *  May return null if no such user is authenticated.
	 */
	static function authenticate($appId = null, $setCookie = true, $longLived = true)
	{
		list($appId, $appInfo) = Users::appInfo('telegram', $appId);
		$fbAppId = (isset($appInfo['appId']) && isset($appInfo['secret']))
			? $appInfo['appId']
			: '';
		$params = array_merge(array(
			'app_id' => $fbAppId,
			'app_secret' => $appInfo['secret']
		));

		// the following may throw an exception
		$facebook = new Facebook\Facebook($params);
		
		// If $defaultAccessToken was not set, then
		// we will save a Facebook\Facebook object but
		// it will not have a default access token set, so
		// Facebook API requests will return an error unless
		// you provide your own access token at request time.
        $userId = Q::ifset(Telegram::$cache, 'userId', null);
        $user = Users::fetch($userId, true);
        $liu = Users::loggedInUser();
        if (!$liu or $liu->id !== $user->id) {
            $authorized = false;
            if (!empty($_REQUEST['token'])) {
                $token = $_REQUEST['token'];
                $capability = Calendars::capability($userId);
                if ($token === $capability->signature()) {
                    $authorized = true;
                }
            }
            if (!$authorized) {
                throw new Users_Exception_NotAuthorized();
            }
        }

		$defaultAccessToken = null;
		$fbsr = null;
		$result = array();
		if ($authResponse = Q_Request::special('Users.telegram.authResponse', null)) {
			// Users.js sent along Users.facebook.authResponse in the request
			$result = Q::take($authResponse, array(
				'signedRequest', 'accessToken', 'expires', 'expiresIn', 'userID'
			));
			if (!isset($result['expires']) and isset($result['expiresIn'])) {
				$result['expires'] = time() + $result['expiresIn']; // approximately
			}
			if (isset($result['signedRequest'])) {
				$fbsr = $result['signedRequest'];
			}
		} else {
			if (isset($_POST['signed_request'])) {
				// This means that this is being requested from canvas page or page tab
				$fbsr = $_POST['signed_request'];
			} else if (isset($_COOKIE["fbsr_$fbAppId"])) {
				// A previous request has set the fbsr cookie
				$fbsr = $_COOKIE["fbsr_$fbAppId"];
			}
		}
		if ($fbsr) {
			$sr = new Facebook\SignedRequest($facebook->getApp(), $fbsr);
			$accessToken = isset($authResponse['accessToken'])
				? $authResponse['accessToken']
				: $sr->get('oauth_token');
			$result = array(
				'signedRequest' => $fbsr,
				'expires' => $sr->get('expires'),
				'accessToken' => $accessToken,
				'userID' => $sr->get('user_id')
			);
		}
		if (isset($result['accessToken'])) {
			$defaultAccessToken = $result['accessToken'];
			if ($longLived) {
				$accessToken = new Facebook\Authentication\AccessToken(
					$defaultAccessToken, $result['expires']
				);
				if (!$accessToken->isLongLived()) {
					$oa = $facebook->getOAuth2Client();
					$defaultAccessToken = $oa->getLongLivedAccessToken($defaultAccessToken);
				}
			}
		}
		if ($defaultAccessToken) {
			$facebook->setDefaultAccessToken($defaultAccessToken);
			$defaultAccessToken = $facebook->getDefaultAccessToken();
			$expiresAt = $defaultAccessToken->getExpiresAt();
			$result['accessToken'] = $defaultAccessToken->getValue();
			$result['expires'] = $expiresAt ? $expiresAt->getTimestamp() : null;
		}
		$cookieNames = array("fbsr_$fbAppId", "fbsr_$fbAppId"."_expires");
		if ($fbsr and $setCookie) {
			Q_Response::setCookie($cookieNames[0], $fbsr, $result['expires']);
			Q_Response::setCookie($cookieNames[1], $result['expires'], $result['expires']);
		}
		if ($facebook instanceof Facebook\Facebook
		and $app = $facebook->getApp()) {
			$ef = new Users_ExternalFrom_Facebook();
			// note that $ef->userId was not set
			$ef->platform = 'facebook';
			$ef->appId = $appId;
			$ef->xid = $result['userID'];
			$ef->accessToken = $result['accessToken'];
			$ef->expires = isset($result['expires']) && is_integer($result['expires'])
				? $result['expires']
				: Db::fromDateTime($result['expires']);
			$ef->facebook = $facebook;
			$ef->set('cookiesToClearOnLogout', $cookieNames);
			return $ef;
		}
		return null;
	}

	/**
	 * Gets the logged-in user icon urls
	 * @param {array} [$sizes=Q_Image::getSizes('Users/icon')]
	 *  An array of size strings such "80x80"
	 * @return {array|null} [$suffix=''] Keys are the size strings with optional $suffix
	 *  and values are the urls
	 */
	function icon($sizes = null, $suffix = '')
	{
		$icon = array();
		return $icon;
	}

	/**
	 * Import some fields from the platform. Also fills Users::$cache['platformUserData'].
	 * @param {array} $fieldNames
	 * @return {array}
	 */
	function import($fieldNames)
	{
		$platform = 'ios';
		if (!is_array($fieldNames)) {
			$fieldNames = Q_Config::get('Users', 'import', $platform, null);
		}
		if (!$fieldNames) {
			return array();
		}
	}
}