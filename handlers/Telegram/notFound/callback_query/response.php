<?php

function Telegram_notFound_callback_query_response($params)
{
    $appId = $params['appId'];
    $update = $params['update'];
    $text = Q_Text::get('Telegram/content');
    Telegram_Bot::answerCallbackQuery(
        $appId, $update['callback_query']['id'],
        [ 'text' => $text['notFound']['NotImplemented'] ]
    );
}