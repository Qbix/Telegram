<?php

function Telegram_before_Q_Request_platform($params, &$result)
{
    if (Q::$controller === 'Telegram_Controller') {
        $result = 'telegram';
    }
}