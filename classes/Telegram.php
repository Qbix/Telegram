<?php
/**
 * Telegram model
 * @module Telegram
 * @main Telegram
 */
/**
 * Static methods for the Telegram models.
 * @class Telegram
 * @extends Base_Telegram
 */
abstract class Telegram extends Base_Telegram
{
	/*
	 * This is where you would place all the static methods for the models,
	 * the ones that don't strongly pertain to a particular row or table.
	 * If file 'Telegram.php.inc' exists, its content is included
	 * * * */

	/* * * */

	/**
	 * Get the secret token to send to telegram in secret_token parameter
	 * of setWebhook()
	 * @method secretToken
	 * @static
	 * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
	 * @return {string}
	 * @throws {Q_Exception_MissingConfig}
	 */
	static function secretToken($appId)
	{
		return Users::secretToken('telegram', $appId);
	}

	/**
	 * Given data sent by Telegram, verify that it is properly signed.
	 * @method verifyData
	 * @static
	 * @param {string|array} $data Data sent by Telegram. Could be a querystring instead of an array.
	 *   Contains the "hash" that is removed from the data, and verified against.
	 * @param {boolean} [$skipExpirationCheck] Pass true here to skip checking the auth_date
	 * @return {boolean} Returns true if the data is properly signed, and not expired
	 */
	static function verifyData($data, $skipExpirationCheck = false)
	{
		if (is_string($data)) {
			parse_str($data, $arr);
			$data = $arr;
		}
		if (!isset($data['hash'])) {
			return false;
		}
		if (!$skipExpirationCheck) {
			if (!isset($data['auth_date'])
			or !Q_Valid::expiration($data['auth_date'])) {
				return false;
			}
		}
		$hash = $data['hash'];
		unset($data['hash']);
		Q_Utils::ksort($data);
		$lines = array();
		foreach ($data as $k => $v) {
			$lines[] = "$k=$v";
		}
		$serialized = implode("\n", $lines);
		list($appId, $info) = Users::appInfo('telegram', $appId);
		$token = $info['token'];
		$key = hash_hmac('sha256', $token, 'WebAppData', true);
		return $hash === hash_hmac('sha256', $serialized, $key);
	}

	/**
	 * Get a deterministic session ID
	 * @method sessionId
	 * @static
	 * @param {string} $appId The internal app ID
	 * @param {string} $telegramUserId The user's ID on telegram
	 * @return {string}
	 */
	static function sessionId($appId, $telegramUserId)
	{
		$deterministicSeed = "telegram-$appId-$telegramUserId";
		return Q_Session::generateId($deterministicSeed, 'internal');
	}

	/**
	 * Get the 
	 * @method approximateRegistrationDate
	 * @static
	 * @param {string} $telegramUserId the ID of the Telegram User
	 * @return {string} a date-time string in the format "Y-m-d h:i:s"
	 */
	static function approximateRegistrationDate($telegramUserId)
	{
		$tree = new Q_Tree();
		$dates = $tree->load(TELEGRAM_PLUGIN_CONFIG_DIR.DS.'dates.json')->get('dateByUserId');
		$prevDate = reset($dates);
		$prevId = intval(key($dates));
		$tId = max($telegramUserId, $prevId);
		foreach ($dates as $currentId => $date) {
			$currentId = intval($currentId);
			if ($currentId < $tId) {
				$prevDate = $date;
				$prevId = $currentId;
				continue;
			}
			$daystampA = Q_Daystamp::fromDateTime($prevDate);
			$daystampB = Q_Daystamp::fromDateTime($date);
			$denom = $currentId - $prevId;
			$fraction = $denom ? (intval($tId) - intval($prevId)) / $denom : 0;
			return Q_Daystamp::toDateTime(
				$daystampA + ($daystampB - $daystampA) * $fraction
			);
		}
		// newer than all dates in the JSON, may as well return yesterday's date
		$daystampNow = Q_Daystamp::fromTimestamp(time());
		return Q_Daystamp::toDateTime($daystampNow) - 1;
	}

	/**
	 * Import the Telegram user data into an array you can assign to a Users_User row
	 * @method import
	 * @static
	 * @param {array} $telegramUser The Telegram user data
	 * @param {array} $fieldNames The field names to import from the Telegram user data
	 * @return {array} The imported data, with keys matching the field names in $fieldNames
	 */
	static function import($telegramUser, $fieldNames = null)
	{
		if (!is_array($fieldNames)) {
			$fieldNames = Q_Config::get('Users', 'import', 'telegram', null);
		}
		if (empty($fieldNames)) {
			return array();
		}
        if (empty($telegramUser)) {
            return array();
        }
        $result = array();
        foreach ($fieldNames as $fn) {
            if (isset($telegramUser[$fn])) {
                $key = $fn;
                if ($fn === 'language_code') {
                    $key = 'preferredLanguage';
                }
                $result[$key] = $telegramUser[$fn];
            }
        }
		return $result;
	}

	/**
	 * Get the a user's icon urls based on xid,
	 * using the corresponding bot to fetch the profile photos
	 * @method icon
	 * @static
	 * @param {string} $appId The app ID
	 * @param {string} $xid The user ID on Telegram
	 * @param {array} [$sizes=Q_Image::getSizes('Users/icon')]
	 *  An array of size strings such "80x80"
	 * @param {string} [$suffix=".png"] Optional suffix to append to the size strings, e.g. ".png"
	 * @return {array|null} Keys are the size strings with optional $suffix
	 *  and values are the urls
	 */
	static function icon($appId, $xid, $sizes = null, $suffix = '.png')
	{
        $sizes = isset($sizes) ? $sizes : array_keys(Q_Image::getSizes('Users/icon'));
        ksort($sizes);

        // Fetch Telegram profile photos
        $photos = Telegram_Bot::getUserProfilePhotos($appId, $xid, 1);
        if (empty($photos) || empty($photos[0][0]['file_id'])) {
            return array();
        }

        $biggest = end($photos[0]);
        $fileId = $biggest['file_id'];
        $info = null;
        $url = Telegram_Bot::getFileURL($appId, $fileId, $info);

        // Return same URL for all sizes
        $icons = array();
        foreach ($sizes as $size) {
            $icons[$size . $suffix] = $url;
        }
        return $icons;
	}
};