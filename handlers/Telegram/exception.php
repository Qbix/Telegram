<?php

function Telegram_exception($params)
{
    $exception = $params['exception'];
    $appId = $params['appId'];
    $update = $params['update'];
    $updateType = $params['updateType'];
    $chatId = Telegram_Bot::chatIdForReply($params);
    $messageText = $exception->getMessage();
    Q::log($exception, 'telegram');
    Telegram_Bot::sendMessage($appId, $chatId, $messageText, [
        'protect_content' => true
    ]);
    echo '{"ok": true"}';
    http_response_code(200);
}