<?php

function Telegram_telegram_my_chat_member_response($params)
{
	$appId = $params['appId'];
	$update = $params['update'];
	$info = $update['my_chat_member'];

	$status = Q::ifset($info, 'new_chat_member', 'status');
	$chatId = Q::ifset($info, 'chat', 'id');
	if (!in_array($status, ['member', 'administrator'])) return;

	$text = Q_Text::get('Telegram/content');
	$greeting = Q::ifset($text, 'activation', 'Thanks', 'Bot activated!');

	Telegram_Bot::sendMessage($appId, $chatId, $greeting);
}
