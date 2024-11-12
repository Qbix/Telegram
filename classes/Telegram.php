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
		$daystampNow = Q_Daystamp::fromTimestamp(time());
	}
};