<?php

function Telegram_exception($params)
{
    $exception = $params['exception'];
    $appId = $params['appId'];
    $update = $params['update'];
    $updateType = $params['updateType'];
    $chatId = Q::ifset($update, $updateType, 'from', 'id', 
        Q::ifset($update, $updateType, 'user', 'id', null, 
            Q::ifset($update, $updateType, 'chat', 'id', null)
        )
    );
    $messageText = $exception->getMessage();
    Q::log($exception, 'telegram');
    Telegram_Bot::sendMessage($appId, $chatId, $messageText, [
        'protect_content' => true
    ]);
    echo '{"ok": true"}';
    http_response_code(200);
}