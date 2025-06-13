<?php

function Telegram_response($params)
{
	$appId = $params['appId'];
	$updateType = $params['updateType'];
	list($appId, $info) = Users::appInfo('telegram', $appId);
	$module = Q::ifset($info, 'module', $appId);
	$params['module'] = $module;

	$try = [
		"$module/telegram/$updateType/response",
		"Telegram/telegram/$updateType/response",
		"Telegram/notFound/$updateType/response"
	];

	foreach ($try as $eventName) {
		if (Q::canHandle($eventName)) {
			$params['eventName'] = $eventName;
			Q::event($eventName, $params);
			echo '{"ok": true}';
			http_response_code(200);
			return;
		}
	}

	throw new Q_Exception_MethodNotSupported(['method' => $updateType]);
}
