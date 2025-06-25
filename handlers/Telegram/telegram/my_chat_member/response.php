<?php

function Telegram_telegram_my_chat_member_response($params)
{
	$appId = $params['appId'];
	$update = $params['update'];
	$info = $update['my_chat_member'];
	$from = Q::ifset($info, 'from', null);
	$botStatusTime = $info['date'];
	$ncm = $info['new_chat_member'];

	$botStatus = $ncm['status'];
	$chat = $info['chat'];
	$chatId = $chat['id'];
	$chatType = $chat['type']; // private, group, supergroup, channel

	$user = null;
	if ($changedByXid = Q::ifset($from, 'id', null) and !Q::ifset($from, 'is_bot', false)) {
		Users::$cache['platformUserData'] = array(
            'telegram' => Telegram::import($from)
        );
		$user = Users::futureUser('telegram_all', $changedByXid, $futureUserStatus, $inserted);
		// saving the user will trigger hook:
		// Streams_after_Users_User_saveExecute
		// which will look into Users::$cache['platformUserData']['telegram']
		// and import fields
		if ($inserted) {
			if (Q_Config::get('Users', 'futureUser', 'telegram', 'icon', false)) {
				$icon = Telegram::icon($appId, $changedByXid);
				Users::importIcon($user, $icon);
			}
		}
	}

	if (!in_array($botStatus, ['member', 'administrator'])) {
		return;
	}

	if (!empty($from) and isset($from['language_code'])) {
		Q_Text::setLanguage($from['language_code']);
	}
	$key = ($chatType === 'supergroup') ? 'group' : $chatType;
	$greeting = Q::interpolate(array('Telegram/content', array($key, 'added', 'Greeting')));
	if (is_string($greeting) and $greeting) {
		Telegram_Bot::sendMessage($appId, $chatId, $greeting);
	}

	$isForum = Q::ifset($chat, 'is_forum', false);
	$attributes = @compact('changedByXid', 'chatType', 'isForum', 'botStatus', 'botStatusTime');

	$fallback = $chatType === 'private' ? Telegram::name($chat) : 'Telegram Chat';
	$streamName = "Telegram/chat/$chatId";
	$stream = Streams_Stream::fetchOrCreate('Telegram', 'Telegram', $streamName, array(
		'skipAccess' => true,
		'fields' => array(
			'title' => Q::ifset($chat, 'title', $fallback),
			'attributes' => $attributes
		)
	), $results);
	if (!$results['created']) {
		$stream->setAttribute($attributes);
		$stream->changed($user ? $user->id : 'Telegram');
	}
}
