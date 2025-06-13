<?php

function Telegram_telegram_chat_join_request_response($params)
{
	$appId = $params['appId'];
	$update = $params['update'];
	$request = $update['chat_join_request'];

	$chatId = $request['chat']['id'];
	$userId = $request['from']['id'];

	$canMute = Telegram_Bot::getMyChatMemberPermissions($appId, $chatId); // optional helper
	$text = Q_Text::get('Telegram/content');
	$greeting = Q::ifset($text, 'channel', 'request', 'Greeting', "Please answer to enter.");

	if ($canMute) {
		Telegram_Bot::approveChatJoinRequest($appId, $chatId, $userId);
		Telegram_Bot::restrictChatMember($appId, $chatId, $userId); // full mute

		Telegram_Bot::sendMessage($appId, $userId, $greeting);
		// TODO: On dialog success: unrestrict
	} else {
		Telegram_Bot::sendMessage($appId, $userId, $greeting);
		// TODO: On dialog success: approve
	}
}
