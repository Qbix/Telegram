<?php

function Telegram_telegram_chat_member_response($params)
{
	$appId = $params['appId'];
	$update = $params['update'];
	$memberUpdate = $update['chat_member'];

	$newStatus = Q::ifset($memberUpdate, 'new_chat_member', 'status');
	if ($newStatus !== 'member') return;

	$chatId = Q::ifset($memberUpdate, 'chat', 'id');
	$userId = Q::ifset($memberUpdate, 'new_chat_member', 'user', 'id');
	$botUsername = Q_Config::get('Users', 'apps', 'telegram', $appId, 'botUsername');
	$text = Q_Text::get('Telegram/content');

	$greeting = Q::ifset($text, 'channel', 'join', 'Greeting', "Welcome. Please check your DM.");
	$linkTitle = Q::ifset($text, 'channel', 'join', 'LinkTitle', "Start a private chat with the bot");

	$canMute = Telegram_Bot::getMyChatMemberPermissions($appId, $chatId);

	if ($canMute) {
		Telegram_Bot::restrictChatMember($appId, $chatId, $userId);
	}

	try {
		Telegram_Bot::sendMessage($appId, $userId, $greeting);
		// TODO: On dialog success: unrestrict
	} catch (Exception $e) {
		$chatToken = ltrim($chatId, '-'); // remove "-" for URL
		$link = "https://t.me/$botUsername?start=join_$chatToken";
		$publicMsg = "$greeting\n\n[$linkTitle]($link)";
		Telegram_Bot::sendMessage($appId, $chatId, $publicMsg, [
			'disable_notification' => true,
			'parse_mode' => 'Markdown'
		]);
	}
}
