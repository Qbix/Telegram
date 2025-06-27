<?php

function Telegram_telegram_my_chat_member_response($params)
{
	$appId = $params['appId'];
	$update = $params['update'];
	$info = $update['my_chat_member'];
	$chat = $info['chat'];
	$chatId = $chat['id'];
	$chatType = $chat['type'];
	$botStatus = Q::ifset($info['new_chat_member'], 'status');

	if (!in_array($botStatus, ['member', 'administrator'])) {
		return;
	}

	$key = ($chatType === 'supergroup') ? 'group' : $chatType;
	$greeting = Q::interpolate(['Telegram/content', [$key, 'added', 'Greeting']]);

	if (is_string($greeting) && $greeting) {
		Telegram_Bot::sendMessage($appId, $chatId, $greeting);
	}
}
