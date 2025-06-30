<?php

function Telegram_after_Telegram_log($params)
{
	$update = $params['update'];
	$appId = $params['appId'];
	$publisher = Telegram_Bot::getUser($appId);
	$publisherId = $publisher ? $publisher->id : 'Telegram';

	// Extract 'from' user safely
	$from = null;
	if (isset($update['message']['from'])) {
		$from = $update['message']['from'];
	} elseif (isset($update['my_chat_member']['from'])) {
		$from = $update['my_chat_member']['from'];
	}

	$user = null;
	$xid = isset($from['id']) ? $from['id'] : null;
	if ($xid && empty($from['is_bot'])) {
		$user = Telegram::futureUser($appId, $from);
	}

	// Extract chat object
	$chat = null;
	if (isset($update['message']['chat'])) {
		$chat = $update['message']['chat'];
	} elseif (isset($update['my_chat_member']['chat'])) {
		$chat = $update['my_chat_member']['chat'];
	}
	if (!$chat || !isset($chat['id'])) {
		return;
	}

	$chatId = $chat['id'];
	$chatType = isset($chat['type']) ? $chat['type'] : '';
	$isForum = isset($chat['is_forum']) ? $chat['is_forum'] : false;
	$fallback = Telegram::name($chat);
	$streamName = "Telegram/chat/$chatId";

	$stream = Streams_Stream::fetchOrCreate($publisherId, $publisherId, $streamName, array(
		'skipAccess' => true,
		'fields' => array(
			'type' => 'Telegram/chat',
			'title' => isset($chat['title']) ? $chat['title'] : $fallback,
			'attributes' => array()
		)
	), $results);
	
	if ($results['created']) {
		// update the icon of the chat, if any
		if ($icon = Telegram::chatIcon($appId, $chatId)) {
			Streams::importIcon($stream, $icon);
		}
	}

	// Set attributes if coming from my_chat_member
	if (isset($update['my_chat_member'])) {
		$botStatus = isset($update['my_chat_member']['new_chat_member']['status'])
            ? $update['my_chat_member']['new_chat_member']['status']
            : null;
		$botStatusTime = isset($update['my_chat_member']['date'])
            ? $update['my_chat_member']['date']
            : null;
		$attributes = array(
			'xid' => $xid,
			'chatType' => $chatType,
			'isForum' => $isForum,
			'botStatus' => $botStatus,
			'botStatusTime' => $botStatusTime
		);
		$stream->setAttribute($attributes);
		$stream->changed(isset($user) ? $user->id : $publisherId);
	}

	$chatType = isset($chat['type']) ? $chat['type'] : 'private';
    $default = Q_Config::get('Telegram', 'syndicate', 'chatTypes', $chatType, false);
    if (!$stream->getAttribute('Telegram/syndicate', $default)) {
        return;
    }

	// Post message to stream if present
	if (isset($update['message']) && isset($update['message']['text']) and $xid) {
		$content = $update['message']['text'];
		$instructions = $update['message'];
		unset($instructions['text']);

		$stream->join(array('userId' => $user->id, 'noVisit' => true));

		$stream->post($authorUser->id, array(
			'type' => 'Streams/chat/message',
			'content' => $content,
			'instructions' => $instructions
		), true); // skipAccess = true
	}
}
