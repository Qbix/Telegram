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
		$internalSecret = Q_Config::get(
            'Q', 'internal', 'secret', $appId
        );
		list($appId, $info) = Users::appInfo('telegram', $appId, true);
		return $appId . '-' . Q::ifset($info, 'secret', Q_Utils::hmac(
            'sha1', 'Telegram', $internalSecret
        ));
	}
};