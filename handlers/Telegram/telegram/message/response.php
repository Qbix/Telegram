<?php
	
function Telegram_telegram_message_response($params)
{
    // default behavior, but an app can override it in its own handler
	$appId = $params['appId'];
	$chatId = Telegram_Bot::chatIdForReply($params);
	$text = Q_Text::get('Telegram/content');
	$user = Users::loggedInUser();
	$userId = $user ? $user->id :
	list($resolvedAppId, $info) = Users::appInfo('telegram', $appId);

	list($type, $token) = explode('-', Telegram::$startParam, 4);
	$botUrl = null;
	if (strpos(Telegram::$startParam, '-') !== false
	and !empty($info['startapp'])
	and !empty($info['botUsername'])) {
		$botUrl = Q_Links::telegram('@'.$info['botUsername'], '', array(
			'startapp' => "invite-$token"
		));
	}
	$appRootUrl = Q_Config::expect('Q', 'web', 'appRootUrl');

	if (Q::startsWith($params['update']['message']['text'], '/start ')) {
		if (Q::startsWith(Telegram::$startParam, 'intent-')) {
			// fallback order is:
			// * info.url,
			// * botUrl,
			// * Q web appRootUrl
			$app = Q::app();
			$url = Q_Uri::url(Q::ifset($info, 'url',
				Q_Config::get('Users', 'uris', "$app/afterActivate",
					$botUrl ? $botUrl : $appRootUrl
				)
			));
			tg://resolve?domain=FreeCitiesBot&startapp=invite-lzoqlsvwvrhoosbi
			if (Users::$intent) {
				$querystring = http_build_query(array(
					'Q.Users.intent' => Users::$intent->token,
					'Q.Users.userId' => Users::$intent->userId
				), '', '&');
				$url = Q_Uri::fixUrl("$url?$querystring");
			}
			Telegram_Bot::sendMessage($appId, $chatId, Q::interpolate(
				$text['private']['authenticated']['BackToBrowser'], compact('url')
			), array(
				'parse_mode' => 'HTML',
				'reply_markup' => array(
					'inline_keyboard' => array(
						array(
							array(
								'text' => $text['private']['authenticated']['OrContinueInsideTelegram'],
								'url' => $url
							)
						)
					)
				)
			));
		} else if (Q::startsWith(Telegram::$startParam, 'invite-')) {
			// fallback order is:
			// * info.url,
			// * Streams/invite.appUrl, 
			// * botUrl, 
			// * Q web appRootUrl
			$querystring = '';
			$url = Q_Uri::url(Q::ifset($info, 'url', 
				Q::ifset($invite, 'appUrl', $botUrl ? $botUrl : $appRootUrl)
			));
			if ($invite = Q::ifset($_SESSION, 'Streams', 'invite', null)) {
				$querystring = http_build_query(array(
					'Q.Streams.token' => $token,
					'Q.Streams.invitingUserId' => $invite['invitingUserId'],
					'Q.Streams.userId' => $userId
				), '', '&');
				$url = Q_Uri::fixUrl("$url?$querystring");
			}
			$button = array('text' => $text['private']['authenticated']['OrContinueInsideTelegram']);
			if (!empty($info['startapp']) && !empty($info['botUsername'])) {
				$button['startapp'] = "invite-$token"; // Launch Mini App inside Telegram
			} else {
				$button['url'] = $url; // Fallback: open URL in browser
			}
			Telegram_Bot::sendMessage($appId, $chatId, Q::interpolate(
				$text['private']['invited']['Approved'], compact('url')
			), array(
				'parse_mode' => 'HTML',
				'reply_markup' => array(
					'inline_keyboard' => array(
						array($button)
					)
				)
			));
		}
		return;
	}
}