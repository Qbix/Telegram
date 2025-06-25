<?php

function Telegram_0_3_Streams()
{
    Streams_Stream::fetchOrCreate(
        'Telegram',
        'Telegram',
        'Telegram/chats',
        array('skipAccess' => true)
    );
}