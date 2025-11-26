<?php
	
function Telegram_telegram_message_response($params)
{
    // default behavior, but an app can override it in its own handler
	$appId = $params['appId'];
	$chatId = Telegram_Bot::chatIdForReply($params);
	$text = Q_Text::get('Telegram/content');
	list($resolvedAppId, $info) = Users::appInfo('telegram', $appId);
	if (Q::startsWith($params['update']['message']['text'], '/start ')) {
		if (Q::startsWith(Telegram::$startParam, 'intent-')) {
			$app = Q::app();
			$url = Q_Uri::url(Q::ifset($info, 'url',
				Q_Config::get('Users', 'uris', "$app/afterActivate",
					Q_Config::expect('Q', 'web', 'appRootUrl')
				)
			));
			Telegram_Bot::sendMessage($appId, $chatId, $text['private']['authenticated']['BackToBrowser'], compact('url'));
		} else {
			$url = Q_Uri::url(Q::ifset($info, 'url', 
				Q::ifset($_SESSION, 'Streams', 'invite', 'appUrl',
					Q_Config::expect('Q', 'web', 'appRootUrl')
				)
			));
			Telegram_Bot::sendMessage($appId, $chatId, $text['private']['invited']['Approved'], compact('url'));
		}
		return;
	}
}