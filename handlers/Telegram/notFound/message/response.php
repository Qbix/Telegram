<?php

function Telegram_notFound_message_response($params)
{
    $appId = $params['appId'];
    $module = $params['module'];
    $eventName = $params['eventName'];
    $chatId = Telegram_Bot::chatIdForReply($params);
    $text = Q_Text::get('Telegram/content');
    Telegram_Bot::sendMessage(
        $appId, $chatId, Q::interpolate(
            $text['notFound']['Message'],
            ['eventName' => $eventName]
        )
    );
}