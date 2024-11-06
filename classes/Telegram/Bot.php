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
     * @param {string} $appId The appId under Users/apps/telegram config
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
     * @param {string} $appId The appId under Users/apps/telegram config
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

        $plugin = 'Telegram';
        $rows = Telegram::db()->select('*', '{{prefix}}Q_plugin')
        ->where(compact('plugin'))
        ->fetchDbRows();
        if ($rows) {
            $row = reset($rows);
            $extra = json_decode($row->extra, true);
            if ($extra) {
                unset($extra[$appId]['setWebhook']);
                $extra = json_encode($extra);
                Telegram::db()->update('{{prefix}}Q_plugin')
                ->set(compact('extra'))
                ->where(compact('plugin'))
                ->execute();
            }
        }

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
     * @param {string} $appId The appId under Users/apps/telegram config
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
     * Tries to deduce the chat_id to reply to
     * @method chatIdForReply
     * @static
     * @param {Array} $params array with keys "updateType" and "update" containing the Telegram update
     * @return {String} The chat_id that the bot should probably use in sendMessage() replies
     */
    static function chatIdForReply($params)
    {
        Q_Valid::requireFields(['update', 'updateType'], $params);
        $update = $params['update'];
        $updateType = $params['updateType'];
        return Q::ifset($update, $updateType, 'from', 'id', 
            Q::ifset($update, $updateType, 'user', 'id', null, 
                Q::ifset($update, $updateType, 'chat', 'id', null)
            )
        );
    }

    /**
     * Answer callback queries sent from inline keyboards.
     *
     * @method answerCallbackQuery
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
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
     * Use this method to send answers to an inline query. On success, True is returned.
     * No more than 50 results per query are allowed.
     *
     * @method answerInlineQuery
     * @static
     * @param {string} $inline_query_id Unique identifier for the answered query (required)
     * @param {array|string} $results A regular array, or a JSON-serialized array, of results for the inline query (required). See https://core.telegram.org/bots/api#inlinequeryresult
     * @param {object} [$options] Optional parameters:
     * @param {int} [$options.cache_time=300] The maximum amount of time in seconds that the result of the inline query may be cached on the server (optional)
     * @param {boolean} [$options.is_personal=false] Pass True if results may be cached on the server side only for the user that sent the query (optional)
     * @param {string} [$options.next_offset] Pass the offset that a client should send in the next query with the same text to receive more results (optional). Pass an empty string if there are no more results or if pagination is not supported. Offset length can't exceed 64 bytes.
     * @param {object} [$options.button] A JSON-serialized object describing a button to be shown above inline query results (optional)
     * @return {boolean} True on success.
     */
     static function answerInlineQuery($appId, $inline_query_id, $results, array $options)
     {
        if (is_array($results)) {
            $results = json_encode($results, true);
        }
         $params = compact('inline_query_id', 'results');
         Q::take($options, [
             'cache_time', 'is_personal', 'next_offset', 'button'
         ], $params);
         return self::api($appId, "answerInlineQuery", $params);
     }
    
    /**
     * Send text messages. https://core.telegram.org/bots/api#sendmessage
     *
     * @method sendMessage
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {String} $text Text of the message to be sent, 1-4096 characters after entities parsing.
     * @param {Array} [$options=array()]
     * @param {String} [$options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer} [$options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {String} [$options.parse_mode] Mode for parsing entities in the message text. Can be "HTML" or "MarkdownV2"
     * @param {Array<MessageEntity>} [$options.entities] A JSON-serialized list of special entities that appear in the message text, which can be specified instead of parse_mode.
     * @param {LinkPreviewOptions} [$options.link_preview_options] Link preview generation options for the message.
     * @param {Boolean} [$options.disable_notification] Sends the message silently. Users will receive a notification with no sound.
     * @param {Boolean} [$options.protect_content] Protects the contents of the sent message from forwarding and saving.
     * @param {String} [$options.message_effect_id] Unique identifier of the message effect to be added to the message; for private chats only.
     * @param {ReplyParameters} [$options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [$ptions.reply_markup] Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove a reply keyboard or to force a reply from the user.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendMessage($appId, $chat_id, $text, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        if (!is_string($text)) {
            throw new Q_Exception_InvalidInput(array('source' => '$text'));
        }
        $options['chat_id'] = $chat_id;
        $options['text'] = $text;
        if (isset($options['reply_markup'])
        and is_array($options['reply_markup'])) {
            $options['reply_markup'] = json_encode($options['reply_markup'], true);
        }
        return self::api($appId, "sendMessage", $options);
    }

    /**
     * Use this method to send photos. On success, the sent Message is returned.
     * https://core.telegram.org/bots/api#sendphoto
     *
     * @method sendPhoto
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {InputFile|String} photo Photo to send. Pass a file_id as String to send a photo that exists on the Telegram servers, pass an HTTP URL as a String for Telegram to get a video from the Internet, or upload a new video using multipart/form-data.
     * @param {Integer} [options.duration] Duration of sent photo in seconds.
     * @param {Integer} [options.width] Photo width.
     * @param {Integer} [options.height] Photo height.
     * @param {InputFile|String} [options.thumbnail] Thumbnail of the file sent; can be ignored if thumbnail generation is supported server-side. Thumbnail must be in JPEG format and under 200 kB.
     * @param {String} [options.caption] Photo caption (may also be used when resending photos by file_id), 0-1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the photo caption. See formatting options for more details.
     * @param {Array<MessageEntity>} [options.caption_entities] A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode.
     * @param {Boolean} [options.show_caption_above_media] Pass True if the caption must be shown above the message media.
     * @param {Boolean} [options.has_spoiler] Pass True if the photo needs to be covered with a spoiler animation.
     * @param {Boolean} [options.supports_streaming] Pass True if the uploaded photo is suitable for streaming.
     * @param {Boolean} [options.disable_notification] Sends the message silently. Users will receive a notification with no sound.
     * @param {Boolean} [options.protect_content] Protects the contents of the sent message from forwarding and saving.
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added to the message; for private chats only.
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove a reply keyboard, or to force a reply from the user.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendPhoto($appId, $chat_id, $photo, array $options = [])
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'photo'));
        $params['chat_id'] = $chat_id;
        
        return self::api($appId, "sendPhoto", $params);
    }

    /**
     * Send video files. Telegram clients support MPEG4 videos (other formats may be sent as Document).
     * https://core.telegram.org/bots/api#sendvideo
     *
     * @method sendVideo
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
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
        
        
        //actually:
        // if $params['video'] is URL or telegramID, we just sendVideo with header 'Content-Type: application/json'
        // otherwise:
        // - expecting absolute path(for example from $_FILES) and trying to convert into CURLFile object
        // - trigger chat action "upload_video"
        // - sendVideo with header 'Content-Type: multipart/form-data'
                
        if (Q_Valid::url($params['video']) || (preg_match('/^[\/\w\-. ]+$/', $params['video']))) {
            return self::api($appId, "sendVideo", $params, []);
        } else {
            if (!is_a($params['video'], CURLFile)) {
                try {
                    $params['video'] = new CURLFile(realpath($params['video']));
                } catch (Exception $e) {
                    throw new Q_Exception_InvalidInput(array('source' => '$video'));    
                }
            }
            //self::sendChatAction($appId, $chat_id, 'upload_video');
            self::api($appId, 'sendChatAction', [
                'chat_id' => $chat_id,
                'action' => 'upload_video'
            ], []);

            return self::api($appId, "sendVideo", $params, [
                'Accept: application/json',
                'Content-Type: multipart/form-data'
            ]);
        }
    }

    /**
     * Get the URL of a file that is hosted on Telegram
     * @method getFileURL
     * @static
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} $file_id The ID of the file hosted on Telegram
     * @param {String} [&$info] Optionally pass a reference to a variable that will be filled with an array of "size" and "file_unique_id"
     * @return {String} The public URL of the file, for downloading it
     */
    static function getFileURL($appId, $file_id, &$info = null)
    {
        $response = self::api($appId, 'getFile', compact('file_id'));
        $info = $response['result'];
        $token = self::tokenFromConfig($appId);
        return "https://api.telegram.org/file/bot$token/" . $info['file_path'];
    }
    
    /**
     * Perform an action on behalf of the Telegram bot, such as typing, uploading a file, or recording a video.
     * This can be used to indicate that the bot is performing an action (e.g., "typing..." indicator).
     *
     * @method sendChatAction
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {String} $action Type of action to broadcast. Choose one, depending on what the user is about to receive: 
     * - "typing" for text messages, 
     * - "upload_photo" for photos, 
     * - "record_video" or "upload_video" for videos, 
     * - "record_voice" or "upload_voice" for voice notes, 
     * - "upload_document" for general files, 
     * - "choose_sticker" for stickers, 
     * - "find_location" for location data, 
     * - "record_video_note" or "upload_video_note" for video notes.
     * @param {String} [$options.business_connection_id] Optional. Unique identifier of the business connection on behalf of which the action will be sent.
     * @param {Integer} [options.message_thread_id] Optional. Unique identifier for the target message thread; for supergroups only.
     * 
     * @return {Boolean} Returns true on success.
     */
    static function sendChatAction($appId, $chat_id, $action, array $options = [])
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        
        if (!is_string($action)) {
            throw new Q_Exception_InvalidInput(array('source' => '$action'));
        }
        
        $params = array_merge($options, compact('chat_id', 'action'));
        
        return self::api($appId, "sendChatAction", $params);
    }
    
    /**
     * Use this method to approve a chat join request. The bot must be an administrator in the chat for this to work 
     * and must have the can_invite_users administrator right. Returns True on success.
     * 
     * @method approveChatJoinRequest
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {Integer} $user_id Unique identifier of the target user
     * 
     * @return {Boolean} Returns true on success.
     */
    static function approveChatJoinRequest($appId, $chat_id, $user_id) 
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        
        if (!is_int($user_id) && !is_string($user_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$user_id'));
        }
        
        $params = compact('chat_id', 'user_id');
        
        return self::api($appId, "approveChatJoinRequest", $params);
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
     * @param {string} $appId The appId under Users/apps/telegram config
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
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string} $methodName The name of the Telegram Bot API method in https://core.telegram.org/bots/api
     * @param {array} $params 
     * @param {string} [$headers] headers, which can be overrode. Accept and Content-Type are 'application/json' by default
     * @static
     * @return {array} The JSON-decoded response from Telegram
     * @throws {Telegram_Exception_API} if there is an error
     */
    static function api($appId, $methodName, array $params, $headers = [])
    {
        $endpoint = self::endpoint($appId, $methodName);
        //$data = http_build_query($params);
        if (empty($headers)) {
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json'
            ];
        }
        //$response = Q_Utils::post("$endpoint?$data", $payload, Q_Config::get(
        $response = Q_Utils::post($endpoint, $params, Q_Config::get(
            'Telegram', 'bot', 'userAgent', 'Qbix', null
        ), []/*curl_opts*/, $headers, 30, false);

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