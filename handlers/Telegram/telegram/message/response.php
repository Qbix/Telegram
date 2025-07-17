<?php
	
function Telegram_telegram_message_response($params)
{
    // default behavior, but an app can override it in its own handler
	$appId = $params['appId'];
	$chatId = Telegram_Bot::chatIdForReply($params);
	if (Q::startsWith($params['update']['message']['text'], '/start ')) {
		Telegram_Bot::sendMessage($appId, $chatId, 'You can now go back to your browser and refresh the page.');
		return;
	}
}