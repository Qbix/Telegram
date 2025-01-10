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
	 * @param {string} [$appId=Q::app()] Can either be an interal appId or an Telegram appId.
	 * @param {boolean} [$setCookie=true] Whether to set fbsr_$appId cookie
	 * @param {boolean} [$longLived=true] Get a long-lived access token, if necessary
	 * @return {Users_ExternalFrom_Telegram|null}
	 *  May return null if no such user is authenticated.
	 */
	static function authenticate($appId = null, $setCookie = true, $longLived = true)
	{
		list($appId, $appInfo) = Users::appInfo('telegram', $appId);
        if (empty(Telegram_Dispatcher::$update['message']['from']['id'])) {
            return null;
        }
        $telegramUser = Telegram_Dispatcher::$update['message']['from'];
        $xid = $telegramUser['id'];
        if ($minAge = Q::ifset($appInfo, 'authentication', 'minAgeInDays', 0)) {
            $age = 0;
            $date = Telegram::approximateRegistrationDate($telegramUser['id']);
            $daystampNow = Q_Daystamp::fromTimestamp(time());
            $daystampRegistered = Q_Daystamp::fromDateTime($date);
            $ageInDays = $daystampNow - $daystampRegistered;
            if ($ageInDays < $minAge) {
                throw new Users_Exception_AccountTooYoung();
            }
        }
        // if ($botUsername = Q::ifset($appInfo, 'botUsername', null)) {
        //     $cookieNames = array("tgsr_$botUsername", "tgsr_$botUsername"."_expires");
        //     if ($tgsr and $setCookie) {
        //         Q_Response::setCookie($cookieNames[0], $fbsr, $result['expires']);
        //         Q_Response::setCookie($cookieNames[1], $result['expires'], $result['expires']);
        //     }
        // }
        $ef = new Users_ExternalFrom_Telegram();
        // note that $ef->userId was not set
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
		$platform = 'telegram';
		if (!is_array($fieldNames)) {
			$fieldNames = Q_Config::get('Users', 'import', $platform, null);
		}
		if (!$fieldNames) {
			return array();
		}
        if (empty(Telegram_Dispatcher::$update['message']['from'])) {
            return array();
        }
        $result = array();
        $telegramUser = Telegram_Dispatcher::$update['message']['from'];
        foreach ($fieldNames as $fn) {
            if (isset($telegramUser[$fn])) {
                $result[$fn] = $telegramUser[$fn];
            }
        }
        Users::$cache['platformUserData'] = array(
            'telegram' => $result
        );
        return $result;
	}
}