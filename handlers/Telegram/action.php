<?php

function Telegram_action($params)
{
	$appId = $params['appId'];
	$updateType = preg_replace('/[^a-zA-Z0-9_]/', '', $params['updateType']);
	list($appId, $info) = Users::appInfo('telegram', $appId);
	$module = Q::ifset($info, 'module', $appId);
	$params['module'] = $module;

	$try = [
		"$module/telegram/$updateType/action",
		"Telegram/telegram/$updateType/action",
		"Telegram/notFound/$updateType/action"
	];

	foreach ($try as $eventName) {
		if (Q::canHandle($eventName)) {
			$params['eventName'] = $eventName;
			Q::event($eventName, $params);
			return;
		}
	}
}
