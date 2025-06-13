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

        $result = Telegram_Bot::setWebhook($appId, $url, @compact(
            'certificate', 'secret_token'
        ));
        echo "Telegram web hook was set to $url" . PHP_EOL;
        if ($result['error']) {
            throw new Telegram_Exception_Webhook(@compact('app', 'url', 'token', 'certificate'));
        }
        $extra[$appId] = array('setWebhook' => true);
        Q_Plugin::extra('Telegram', 'plugin', 'Telegram', compact('extra'));
    }
}