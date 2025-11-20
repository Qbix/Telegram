<?php
	
function Telegram_telegram_message_response($params)
{
    // default behavior, but an app can override it in its own handler
	$appId = $params['appId'];
	$chatId = Telegram_Bot::chatIdForReply($params);
	$text = Q_Text::get('Telegram/content');
	if (Q::startsWith($params['update']['message']['text'], '/start ')
	and !empty(Telegram::$startParam)) {
		Telegram_Bot::sendMessage($appId, $chatId, $text['private']['authenticated']['BackToBrowser']);
		return;
	}
}