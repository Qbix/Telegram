<?php

/**
 * Used to generate an intent and redirect to Telegram app
 *
 * @module Streams
 * @class HTTP Streams stream
 * @method get
 * @param {array} $_REQUEST 
 * @param {string} [$_REQUEST.bot] The username of the bot on the Telegram network, defaults to the app bot
 * @param {string} $_REQUEST.streamName Required streamName or name
 * @param {integer} [$_REQUEST.messages] optionally pass a number here to fetch latest messages
 * @param {integer} [$_REQUEST.participants] optionally pass a number here to fetch participants
 * @return {void}
 */
function Telegram_authenticate_response()
{
    $refererURL = Q::ifset($_SERVER, 'HTTP_REFERER', null);
    $sessionId = Q_Session::requestedId();
    if (!$sessionId) {
        // no session ID, redirect back if we can
        if ($refererURL) {
            Q_Response::redirect($refererURL);
        } else {
            echo "No active session";
        }
        return false;
    }
    $intent = Users_Intent::newIntent('Users/authenticate');
    $appId = Q::ifset($_REQUEST, 'appId', Q::app());
    list($appId, $info) = Users::appInfo('telegram', $appId);
    $parameter = Q::ifset($_REQUEST, 'parameter', 'start');
    $botUsername = Q::ifset($info, 'botUsername', null);
    if (!$botUsername) {
        throw new Q_Exception_MissingConfig(array('fieldpath' => "Users/apps/telegram/$appId/botUsername"));
    }
    $url = Q_Links::telegram('@'.$info['botUsername'], null, array($parameter => $intent->token));
    Q_Response::redirect($url);
    echo "Redirecting to Telegram";
    return false;
}