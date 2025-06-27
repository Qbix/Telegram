<?php

function Telegram_after_Q_Plugin_install($params)
{
    if ($params['plugin_name'] !== 'Telegram') {
        return;
    }
    $extra = Q_Plugin::extra('Telegram', 'plugin', 'Telegram');
    $apps = Q_Config::get('Users', 'apps', 'telegram', array());
    foreach ($apps as $appId => $info) {
        if (!empty($extra[$appId])) {
            continue; // already installed for this app
        }
        $token = Q::ifset($info, 'token', null);
        $skipWebhook = Q::ifset($info, 'skipWebhook', false);
        if (!$token or $skipWebhook) {
            continue;
        }
        $filename = Q::ifset($info, 'cert', null);
        if ($filename) {
            if (!file_exists($filename)) {
                throw new Q_Exception_MissingFile(compact('filename'));
            }
            $certificate = file_get_contents($filename);
        }
        $secret_token = Telegram::secretToken($appId);
        
        $url = Q::ifset($info, 'webhookUrl', Q_Request::proxyBaseUrl('telegram.php'));
        $allowed_updates = array(
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'inline_query',
            'chosen_inline_result',
            'callback_query',
            'shipping_query',
            'pre_checkout_query',
            'poll',
            'poll_answer',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
            'message_reaction',
            'message_reaction_count',
            'business_connection',
            'business_message',
            'deleted_business_messages',
            'message_auto_delete_timer_changed',
            'forum_topic_created',
            'forum_topic_edited',
            'forum_topic_closed',
            'forum_topic_reopened',
            'general_forum_topic_hidden',
            'general_forum_topic_unhidden',
            'write_access_allowed',
            'user_shared',
            'chat_shared'
        );

        $result = Telegram_Bot::setWebhook($appId, $url, @compact(
            'certificate', 'secret_token', 'allowed_updates'
        ));
        echo "Telegram web hook was set to $url" . PHP_EOL;
        if ($result['error']) {
            throw new Telegram_Exception_Webhook(@compact('appId', 'url', 'token', 'certificate'));
        }
        $extra[$appId] = array('setWebhook' => true);
        Q_Plugin::extra('Telegram', 'plugin', 'Telegram', compact('extra'));

        Telegram_Bot::registerUser($appId);
    }
}