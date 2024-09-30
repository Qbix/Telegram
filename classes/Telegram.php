<?php

function Telegram_response($params)
{
    $appId = $params['appId'];
    $updateType = $params['updateType'];
    list($appId, $info) = Users::appInfo('telegram', $appId);
    $module = Q::ifset($info, 'module', $appId);
    $eventName = "$module/telegram/$updateType/response";
    if (!Q::canHandle($eventName)) {
        $eventName = "Telegram/notFound/$updateType/response";
    }
    if (!Q::canHandle($eventName)) {
        $method = $updateType;
        throw new Q_Exception_MethodNotSupported(@compact('method'));
    }
    Q::event($eventName, $params);
    echo '{"ok": true"}';
    http_response_code(200);
}