<?php
	
function Telegram_telegram_message_response($params)
{
    // default behavior, but an app can override it in its own handler
	$appId = $params['appId'];
	$chatId = Telegram_Bot::chatIdForReply($params);
	$text = Q_Text::get('Telegram/content');
	list($resolvedAppId, $info) = Users::appInfo('telegram', $appId);
	if (Q::startsWith($params['update']['message']['text'], '/start ')) {
		$url = Q::ifset($info, 'url', Q_Config::expect('Q', 'web', 'appRootUrl'));
		if (Q::startsWith(Telegram::$startParam, 'intent-')) {
			Telegram_Bot::sendMessage($appId, $chatId, $text['private']['authenticated']['BackToBrowser'], compact('url'));
		} else {
			Telegram_Bot::sendMessage($appId, $chatId, $text['private']['invited']['Approved'], compact('url'));
		}
		return;
	}
}