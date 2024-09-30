<?php
/**
 * Telegram Bot
 * @module Telegram
 */
/**
 * Static methods for the Telegram Bot model.
 * @class Telegram_Bot
 * extends Base_Telegram_Bot
 * @abstract
 */
class Telegram_Bot //extends Base_Telegram_Bot 
{   
    /**
     * Set the webhook for the bot.
     *
     * @method setWebhook
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {String} $url HTTPS URL to send updates to. Use an empty string to remove webhook integration. (Required)
     * @param {Array} [$options] Optional parameters for configuring the webhook.
     * @param {String} [$options.certificate] Provider content of your public .pem file so that the root certificate in use can be checked. See the self-signed guide for details. https://core.telegram.org/bots/self-signed
     * @param {String} [$options.ip_address] The fixed IP address which will be used to send webhook requests instead of the IP address resolved through DNS.
     * @param {Integer} [$options.max_connections] The maximum allowed number of simultaneous HTTPS connections to the webhook for update delivery, between 1-100. Defaults to 40.
     * @param {String|array} [$options.allowed_updates] A JSON-serialized list of the update types you want your bot to receive. Specify an empty list to receive all update types except chat_member, message_reaction, and message_reaction_count (default).
     * @param {Boolean} [$options.drop_pending_updates] Pass true to drop all pending updates. Defaults to false.
     * @param {String} [$options.secret_token] A secret token (1-256 characters) to be sent in a header “X-Telegram-Bot-Api-Secret-Token” in every webhook request.
     *
     * @return {Boolean} Returns true on success.
     */
    public static function setWebhook($appId, $url, $options = [])
    {
        $token = self::tokenFromConfig($appId);
        if (!empty($options['allowed_updates'])
        and is_array($options['allowed_updates'])) {
            $options['allowed_updates'] = json_encode($options['allowed_updates']);
        }
        $params = [
            'url' => $url,
        ];
        Q::take($options, [
            'certificate', 'ip_address', 'allowed_updates', 'secret_token',
            'max_connections', 'drop_pending_updates'
        ], $params);
        return self::api($appId, 'setWebhook', $params);
    }

    /**
     * Deletes any webhook and goes back to working with getUpdates()
     *
     * @method deleteWebhook
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {Array} [$options] Optional parameters for configuring the webhook.
     * @param {String} [$options.drop_pending_updates] Pass True to drop all pending updates
     *
     * @return {Boolean} Returns true on success.
     */
    public static function deleteWebhook($appId, $options = [])
    {
        $token = self::tokenFromConfig($appId);
        $params = [];
        Q::take($options, ['drop_pending_updates'], $params);
        return self::api($appId, 'deleteWebhook', $params);
    }

    /**
     * Returns the type of the update, from a list of predefined types.
     * If Telegram starts supporting more types of updates, this may return null.
     * @method getUpdateType
     * @static
     * @param {array} $update
     * @return {string|null} the type of update
     */
    static function getUpdateType(array $update = [])
    {
        $types = [
            'message', 'edited_message', 
            'channel_post', 'edited_channel_post', 
            'business_connection', 'business_message', 
            'edited_business_message', 'deleted_business_messages', 
            'message_reaction', 'message_reaction_count', 
            'inline_query', 'chosen_inline_result', 'callback_query', 
            'shipping_query', 'pre_checkout_query', 'poll', 
            'poll_answer', 'my_chat_member', 'chat_member', 
            'chat_join_request', 'chat_boost', 'removed_chat_boost'
        ];
        $intersect = array_intersect(array_keys($update), $types);
        $updateType = reset($intersect);
        return $updateType ? $updateType : null;
    }
    
    /**
     * Get incoming updates using long polling. https://core.telegram.org/bots/api#getupdates
     *
     * @method getUpdates
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {Array} [$options] Optional parameters for retrieving updates.
     * @param {Integer} [$options.offset] Identifier of the first update to be returned. Must be greater by one than the highest among the identifiers of previously received updates.
     * @param {Integer} [$options.limit] Limits the number of updates to be retrieved. Values between 1-100 are accepted. Defaults to 100.
     * @param {Integer} [$options.timeout] Timeout in seconds for long polling. Defaults to 0 for short polling.
     * @param {Array<String>} [$options.allowed_updates] A JSON-serialized list of the update types you want your bot to receive. Specify an empty list to receive all update types except chat_member, message_reaction, and message_reaction_count (default).
     *
     * @return {Array} Returns an array of Update objects.
     */
    static function getUpdates($appId, array $options)
    {
        $d = self::api($appId, "getUpdates", $options);
        return $d['result'];
    }

    /**
     * Answer callback queries sent from inline keyboards.
     *
     * @method answerCallbackQuery
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {String} $callback_query_id Unique identifier for the query to be answered.
     * @param {String} [$options.text] Text of the notification. If not specified, nothing will be shown to the user, 0-200 characters.
     * @param {Boolean} [$options.show_alert] If True, an alert will be shown by the client instead of a notification at the top of the chat screen. Defaults to false.
     * @param {String} [$options.url] URL that will be opened by the user's client. For bots with games, use this to open your game or t.me links to start the bot with parameters.
     * @param {Integer} [$options.cache_time] The maximum amount of time in seconds that the result of the callback query may be cached client-side. Defaults to 0.
     *
     * @return {Boolean} Returns true on success.
     */

    static function answerCallbackQuery($appId, $callback_query_id, array $options)
    {
        $params = compact('callback_query_id');
        Q::take($options, [
            'text', 'show_alert', 'url', 'cache_time'
        ], $params);
        return self::api($appId, "answerCallbackQuery", $params);
    }
    
    /**
     * Send text messages. https://core.telegram.org/bots/api#sendmessage
     *
     * @method sendMessage
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {String} text Text of the message to be sent, 1-4096 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the message text. See formatting options for more details.
     * @param {Array<MessageEntity>} [options.entities] A JSON-serialized list of special entities that appear in the message text, which can be specified instead of parse_mode.
     * @param {LinkPreviewOptions} [options.link_preview_options] Link preview generation options for the message.
     * @param {Boolean} [options.disable_notification] Sends the message silently. Users will receive a notification with no sound.
     * @param {Boolean} [options.protect_content] Protects the contents of the sent message from forwarding and saving.
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added to the message; for private chats only.
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove a reply keyboard or to force a reply from the user.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendMessage($appId, $chat_id, $text, array $params)
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        if (!is_string($text)) {
            throw new Q_Exception_InvalidInput(array('source' => '$text'));
        }
        $params['chat_id'] = $chat_id;
        $params['text'] = $text;
        return self::api($appId, "sendMessage", $params);
    }

    /**
     * Send video files. Telegram clients support MPEG4 videos (other formats may be sent as Document).
     * https://core.telegram.org/bots/api#sendvideo
     *
     * @method sendVideo
     * @static
     *
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {InputFile|String} video Video to send. Pass a file_id as String to send a video that exists on the Telegram servers, pass an HTTP URL as a String for Telegram to get a video from the Internet, or upload a new video using multipart/form-data.
     * @param {Integer} [options.duration] Duration of sent video in seconds.
     * @param {Integer} [options.width] Video width.
     * @param {Integer} [options.height] Video height.
     * @param {InputFile|String} [options.thumbnail] Thumbnail of the file sent; can be ignored if thumbnail generation is supported server-side. Thumbnail must be in JPEG format and under 200 kB.
     * @param {String} [options.caption] Video caption (may also be used when resending videos by file_id), 0-1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the video caption. See formatting options for more details.
     * @param {Array<MessageEntity>} [options.caption_entities] A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode.
     * @param {Boolean} [options.show_caption_above_media] Pass True if the caption must be shown above the message media.
     * @param {Boolean} [options.has_spoiler] Pass True if the video needs to be covered with a spoiler animation.
     * @param {Boolean} [options.supports_streaming] Pass True if the uploaded video is suitable for streaming.
     * @param {Boolean} [options.disable_notification] Sends the message silently. Users will receive a notification with no sound.
     * @param {Boolean} [options.protect_content] Protects the contents of the sent message from forwarding and saving.
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added to the message; for private chats only.
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove a reply keyboard, or to force a reply from the user.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendVideo($appId, $chat_id, $video, array $options = [])
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'video'));
        $params['chat_id'] = $chat_id;
        
        return self::api($appId, "sendVideo", $params);
    }

    /**
     * Get bot token from appId in config
     * @method tokenFromConfig
     * @static
     * @param {string} $appId The appId under Users/apps/telegram config
     * @return {string}
     * @throws {Q_Exception_MissingConfig}
     */
    protected static function tokenFromConfig($appId)
    {
        list($appId, $info) = Users::appInfo("telegram", $appId, true);
        return Q::ifset($info, 'token', null);
    }
    
    /**
     * Calculate an endpoint for calling methods
     * @method endpoint
     * @static
     * @param {String} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {String} $methodName The name of the Telegram Bot API method in https://core.telegram.org/bots/api
     */
    private static function endpoint($appId, $methodName) 
    {
        $token = self::tokenFromConfig($appId);
        return  "https://api.telegram.org/bot$token/$methodName";
    }
    
    /**
     * Call the Telegram Bot API
     * @method api
     * @param {string} $appId The username of the Telegram bot, found in local/app.json under Users/apps/telegram config
     * @param {string} $methodName The name of the Telegram Bot API method in https://core.telegram.org/bots/api
     * @param {array} $params 
     * @param {string} [$payload] Any payload for uploads using multipart/form-data
     * @static
     * @return {array} The JSON-decoded response from Telegram
     * @throws {Telegram_Exception_API} if there is an error
     */
    static function api($appId, $methodName, array $params, $payload = null)
    {
        $endpoint = self::endpoint($appId, $methodName);
        $data = http_build_query($params);
        $headers = [
            'Accept'=> 'application/json',
        ];
        if ($payload) {
            $headers['Content-Type'] = 'multipart/form-data';
        }
        $response = Q_Utils::post("$endpoint?$data", $payload, Q_Config::get(
            'Telegram', 'bot', 'userAgent', 'Qbix', null
        ), [], $headers, 30, false);
        $arr = Q::json_decode($response, true);
        Q_Valid::requireFields(array('ok'), $arr, true);
        if ($arr['ok'] !== true) {
            throw new Telegram_Exception_API(Q::take($arr, [
                'error_code' => 400,
                'description' => 'Undocumented Error'
            ]));
        }
        return $arr;
    }
}