<?php
	
function Telegram_after_Streams_create_Telegram_chat($params)
{
	$chat = $params['stream'];
	$type = $chat->getAttribute("type");
	$startTime = $chat->getAttribute("startTime");
	$endTime = $chat->getAttribute("endTime");
	$weight = time();
    $categoryStream = Streams_Stream::fetchOrCreate(
        'Telegram',
        'Telegram',
        'Telegram/chats',
        array('skipAccess' => true)
    );
    $chat->relateTo($categoryStream, 'Telegram/chat', null, array(
        'skipAccess' => true,
        'weight' => $weight
    ));
}