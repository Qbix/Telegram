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
     * Gets the Users_User corresponding to the bot for a given appId, if registered.
     *
     * @method getUser
     * @static
     * @param {string} $appId The app ID in Users/apps/telegram
     * @return {Users_User|null} The associated user object, or null if not registered
     */
    static function getUser($appId) {
        $platform = 'telegram';
        $botInfo = self::getMe($appId);
        $bot = $botInfo['result'];
        $xid = (string)$bot['id'];

        $ef = Users_ExternalFrom::select()
            ->where('platform', $platform)
            ->where('appId', $appId)
            ->where('xid', $xid)
            ->fetchDbRow();

        if (!$ef) {
            return null;
        }
        return Users_User::fetch($ef->userId, true);
    }

    /**
     * Registers the bot as a full Users_User if not already present.
     *
     * @method registerUser
     * @static
     * @param {string} $appId The app ID in Users/apps/telegram
     * @return {Users_User} The saved or existing user object
     */
    static function registerUser($appId) {
        $platform = 'telegram';
        $platformApp = $platform . '_all';

        // Get bot info from Telegram
        $botInfo = self::getMe($appId);
        $bot = $botInfo['result'];
        $xid = (string)$bot['id']; // Telegram user ID of the bot

        // Check if already linked
        $ef = Users_ExternalFrom::select()
            ->where('platform', $platform)
            ->where('appId', $appId)
            ->where('xid', $xid)
            ->fetchDbRow();
        if ($ef) {
            return Users_User::fetch($ef->userId, true);
        }

        // Set up import data
        Users::$cache['importUserData'] = array(
            'platform' => $platform,
            'appId' => $appId,
            'xid' => $xid,
            'platformUserData' => array(
                'telegram' => array(
                    'id' => $xid,
                    'username' => Q::ifset($bot, 'username', ''),
                    'first_name' => Q::ifset($bot, 'first_name', ''),
                    'last_name' => Q::ifset($bot, 'last_name', '')
                )
            )
        );

        // Insert a new Users_User and get a unique userId
        $user = new Users_User();
        $user->signedUpWith = 
        $user->save();

        // Import icon, if configured
        if (Q_Config::get('Users', 'futureUser', 'telegram', 'icon', false)) {
            $icon = Telegram::icon($appId, $xid);
            Users::importIcon($user, $icon);
        }

        // Save ExternalFrom (will auto-save ExternalTo)
        $ef = new Users_ExternalFrom(array(
            'platform' => $platform,
            'appId' => $appId,
            'xid' => $xid,
            'userId' => $user->id
        ));
        $ef->save(true);

        // Save Identify
        list($hashed, $ui_type) = Users::hashing($xid, $platformApp);
        $ui = new Users_Identify();
        $ui->identifier = "$ui_type:$hashed";
        $ui->state = 'verified';
        $ui->userId = $user->id;
        $ui->save(true);

        // Clear cache after import
        Users::$cache['importUserData'] = null;

        return $user;
    }

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
     * @param {array} [$options.interpolate] An array of parameters to interpolate into the URL, such as proxyBaseUrl or reallyBaseUrl
     *
     * @return {Boolean} Returns true on success.
     */
    public static function setWebhook($appId, $url, $options = array())
    {
        $token = self::tokenFromConfig($appId);
        if (!empty($options['allowed_updates'])
        and is_array($options['allowed_updates'])) {
            $options['allowed_updates'] = json_encode($options['allowed_updates']);
        }
        $interpolate = array();
        if (!empty($options['interpolate'])) {
            $interpolate = $options['interpolate'];
            unset($options['interpolate']);
        }
        $params = [
            'url' => Q_Uri::interpolateUrl($url, $interpolate, true)
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
    public static function deleteWebhook($appId, $options = array())
    {
        $token = self::tokenFromConfig($appId);
        $params = array();
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
    static function getUpdateType(array $update = array())
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
    static function sendChatAction($appId, $chat_id, $action, array $options = array())
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
    static function sendPhoto($appId, $chat_id, $photo, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'photo'));
        $params['chat_id'] = $chat_id;
        
        return self::api($appId, "sendPhoto", $params);
    }

    /**
     * Use this method to send audio files, if you want Telegram clients to display them in the music player.
     * https://core.telegram.org/bots/api#sendaudio
     *
     * @method sendAudio
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {InputFile|String} audio Audio file to send. Pass a file_id as String to send a file that exists on Telegram servers, pass an HTTP URL for Telegram to fetch, or upload using multipart/form-data.
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {String} [options.caption] Audio caption, 0-1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the audio caption.
     * @param {Array<MessageEntity>} [options.caption_entities] A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode.
     * @param {Integer} [options.duration] Duration of the audio in seconds.
     * @param {String} [options.performer] Performer of the audio.
     * @param {String} [options.title] Track name.
     * @param {InputFile|String} [options.thumbnail] Thumbnail for the file sent; JPEG under 200 kB, 320px max per dimension.
     * @param {Boolean} [options.disable_notification] Sends the message silently.
     * @param {Boolean} [options.protect_content] Protects the contents from forwarding and saving.
     * @param {Boolean} [options.allow_paid_broadcast] Allows high-throughput delivery (0.1 Stars per message).
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added (private chats only).
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendAudio($appId, $chat_id, $audio, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'audio'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendAudio", $params);
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
    static function sendVideo($appId, $chat_id, $video, array $options = array())
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
            return self::api($appId, "sendVideo", $params, array());
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
            ], array());

            return self::api($appId, "sendVideo", $params, [
                'Accept: application/json',
                'Content-Type: multipart/form-data'
            ]);
        }
    }

    /**
     * Use this method to send rounded square video messages (video notes).
     * https://core.telegram.org/bots/api#sendvideonote
     *
     * @method sendVideoNote
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Chat ID or @channelusername.
     * @param {InputFile|String} video_note Video note file_id or upload. Must be a rounded MPEG4 file (max 1 min).
     * @param {Integer} [options.message_thread_id] Forum thread ID (supergroups).
     * @param {Integer} [options.duration] Duration in seconds.
     * @param {Integer} [options.length] Video width/height (diameter in px).
     * @param {InputFile|String} [options.thumbnail] JPEG thumbnail < 200kB, max 320x320.
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] Paid high-volume messaging flag.
     * @param {String} [options.message_effect_id] Optional effect ID (private chats only).
     * @param {ReplyParameters} [options.reply_parameters] Reply descriptor.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI reply markup.
     *
     * @return {Message} The sent Message on success.
     */
    static function sendVideoNote($appId, $chat_id, $video_note, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'video_note'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendVideoNote", $params);
    }

    /**
     * Use this method to send audio files as voice messages.
     * https://core.telegram.org/bots/api#sendvoice
     *
     * @method sendVoice
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {InputFile|String} voice Audio file in .OGG OPUS format, or a file_id or URL.
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {String} [options.caption] Voice message caption, 0-1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the caption.
     * @param {Array<MessageEntity>} [options.caption_entities] A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode.
     * @param {Integer} [options.duration] Duration of the voice message in seconds.
     * @param {Boolean} [options.disable_notification] Sends the message silently.
     * @param {Boolean} [options.protect_content] Protects the contents from forwarding and saving.
     * @param {Boolean} [options.allow_paid_broadcast] Allows high-throughput delivery (0.1 Stars per message).
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added (private chats only).
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendVoice($appId, $chat_id, $voice, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'voice'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendVoice", $params);
    }

    /**
     * Use this method to send animation files (GIF or H.264/MPEG-4 AVC video without sound).
     * https://core.telegram.org/bots/api#sendanimation
     *
     * @method sendAnimation
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {InputFile|String} animation Animation to send. Use file_id, URL, or upload via multipart/form-data.
     * @param {Integer} [options.message_thread_id] Target message thread ID (for supergroups).
     * @param {Integer} [options.duration] Duration of the animation in seconds.
     * @param {Integer} [options.width] Width of the animation.
     * @param {Integer} [options.height] Height of the animation.
     * @param {InputFile|String} [options.thumbnail] JPEG thumbnail, <200kB, max 320x320.
     * @param {String} [options.caption] Caption, 0–1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Caption parse mode ("Markdown", "HTML").
     * @param {Array<MessageEntity>} [options.caption_entities] JSON array of special entities (alternative to parse_mode).
     * @param {Boolean} [options.show_caption_above_media] Pass true to show caption above the animation.
     * @param {Boolean} [options.has_spoiler] Pass true to cover the animation with a spoiler effect.
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] Allows high-throughput delivery for a fee.
     * @param {String} [options.message_effect_id] Optional message effect for private chats.
     * @param {ReplyParameters} [options.reply_parameters] Message reply descriptor.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI reply markup.
     *
     * @return {Message} The sent Message on success.
     */
    static function sendAnimation($appId, $chat_id, $animation, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'animation'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendAnimation", $params);
    }

    /**
     * Use this method to send static .WEBP, animated .TGS, or video .WEBM stickers.
     * https://core.telegram.org/bots/api#sendsticker
     *
     * @method sendSticker
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or @channelusername.
     * @param {InputFile|String} sticker Sticker to send (file_id, HTTP URL, or uploaded via multipart/form-data).
     * @param {Integer} [options.message_thread_id] Target message thread ID (for forums).
     * @param {String} [options.emoji] Emoji associated with the sticker.
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Protects the sticker from forwarding or saving.
     * @param {String} [options.message_effect_id] Message effect ID for private chats.
     * @param {ReplyParameters} [options.reply_parameters] Reply configuration.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options.
     *
     * @return {Message} The sent sticker Message on success.
     */
    static function sendSticker($appId, $chat_id, $sticker, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'sticker'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendSticker", $params);
    }

    /**
     * Use this method to send a group of photos, videos, audios or documents as an album.
     * https://core.telegram.org/bots/api#sendmediagroup
     *
     * @method sendMediaGroup
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Target chat ID or @channelusername.
     * @param {Array} media JSON-serialized array of 2–10 InputMediaPhoto, InputMediaVideo, etc.
     * @param {Integer} [options.message_thread_id] Forum thread ID (for supergroups).
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] Enables high-volume delivery for 0.1 Stars/msg.
     * @param {String} [options.message_effect_id] Optional message effect (private chats).
     * @param {ReplyParameters} [options.reply_parameters] Optional reply descriptor.
     *
     * @return {Array<Message>} Returns an array of sent messages.
     */
    static function sendMediaGroup($appId, $chat_id, array $media, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $options['media'] = $media;
        $params = array_merge($options, compact('chat_id'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendMediaGroup", $params);
    }

    /**
     * Use this method to send point on the map.
     * https://core.telegram.org/bots/api#sendlocation
     *
     * @method sendLocation
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Chat ID or @channelusername.
     * @param {Float} latitude Latitude of the location.
     * @param {Float} longitude Longitude of the location.
     * @param {Integer} [options.message_thread_id] Target message thread ID (for forums).
     * @param {Float} [options.horizontal_accuracy] Radius of uncertainty for the location, in meters (0–1500).
     * @param {Integer} [options.live_period] Period in seconds to update the location (60–86400).
     * @param {Integer} [options.heading] Direction in which the user is moving (1–360).
     * @param {Integer} [options.proximity_alert_radius] Max distance for proximity alerts, in meters (1–100000).
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] Paid high-throughput messaging flag.
     * @param {String} [options.message_effect_id] Optional message effect ID (private chats).
     * @param {ReplyParameters} [options.reply_parameters] Reply configuration.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI reply markup.
     *
     * @return {Message} The sent Message on success.
     */
    static function sendLocation($appId, $chat_id, $latitude, $longitude, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'latitude', 'longitude'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendLocation", $params);
    }

    /**
     * Use this method to send information about a venue.
     * https://core.telegram.org/bots/api#sendvenue
     *
     * @method sendVenue
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Chat ID or @channelusername.
     * @param {Float} latitude Latitude of the venue.
     * @param {Float} longitude Longitude of the venue.
     * @param {String} title Name of the venue.
     * @param {String} address Address of the venue.
     * @param {Integer} [options.message_thread_id] Target message thread ID (forums).
     * @param {String} [options.foursquare_id] Foursquare identifier.
     * @param {String} [options.foursquare_type] Foursquare type (e.g., "arts_entertainment/default").
     * @param {String} [options.google_place_id] Google Places ID.
     * @param {String} [options.google_place_type] Google Place type (e.g., "restaurant").
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] High-volume delivery flag.
     * @param {String} [options.message_effect_id] Optional visual effect.
     * @param {ReplyParameters} [options.reply_parameters] Reply config.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI reply markup.
     *
     * @return {Message} The sent Message on success.
     */
    static function sendVenue($appId, $chat_id, $latitude, $longitude, $title, $address, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'latitude', 'longitude', 'title', 'address'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendVenue", $params);
    }

    /**
     * Use this method to send phone contacts.
     * https://core.telegram.org/bots/api#sendcontact
     *
     * @method sendContact
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Target chat or channel username.
     * @param {String} phone_number Contact's phone number.
     * @param {String} first_name Contact's first name.
     * @param {Integer} [options.message_thread_id] ID of the thread in a forum.
     * @param {String} [options.last_name] Contact's last name.
     * @param {String} [options.vcard] Additional data about the contact in vCard format (0–2048 bytes).
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_sending_without_reply] If true, ignores reply_to_message_id if message is not found.
     * @param {Boolean} [options.allow_paid_broadcast] High-throughput delivery flag.
     * @param {String} [options.message_effect_id] Optional visual effect (private chats).
     * @param {ReplyParameters} [options.reply_parameters] Config for replying.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI markup.
     *
     * @return {Message} The sent contact Message on success.
     */
    static function sendContact($appId, $chat_id, $phone_number, $first_name, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'phone_number', 'first_name'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendContact", $params);
    }
    


    /**
     * Use this method to send general files.
     * https://core.telegram.org/bots/api#senddocument
     *
     * @method sendDocument
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {InputFile|String} document File to send. Pass a file_id, an HTTP URL, or upload using multipart/form-data.
     * @param {Integer} [options.message_thread_id] Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     * @param {InputFile|String} [options.thumbnail] Thumbnail to use (JPEG < 200kB, max 320x320).
     * @param {String} [options.caption] Document caption, 0-1024 characters after entities parsing.
     * @param {String} [options.parse_mode] Mode for parsing entities in the caption.
     * @param {Array<MessageEntity>} [options.caption_entities] A JSON-serialized list of special entities that appear in the caption, which can be specified instead of parse_mode.
     * @param {Boolean} [options.disable_content_type_detection] Disable automatic content type detection.
     * @param {Boolean} [options.disable_notification] Sends the message silently.
     * @param {Boolean} [options.protect_content] Protects the contents from forwarding and saving.
     * @param {Boolean} [options.allow_paid_broadcast] Allows high-throughput delivery (0.1 Stars per message).
     * @param {String} [options.message_effect_id] Unique identifier of the message effect to be added (private chats only).
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Additional interface options.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendDocument($appId, $chat_id, $document, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'document'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendDocument", $params);
    }

    /**
     * Use this method to send a native poll.
     * https://core.telegram.org/bots/api#sendpoll
     *
     * @method sendPoll
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Unique identifier for the target chat or username of the target channel (in the format @channelusername).
     * @param {String} question Poll question, 1–300 characters.
     * @param {Array<InputPollOption>} options JSON-serialized list of 2–10 answer options.
     * @param {Integer} [options.message_thread_id] Unique identifier for the message thread (supergroups).
     * @param {String} [options.question_parse_mode] Mode for parsing entities in the question. Only custom emoji allowed.
     * @param {Array<MessageEntity>} [options.question_entities] List of special entities in the question (alternative to parse_mode).
     * @param {String} [options.type] Poll type: "regular" or "quiz" (defaults to "regular").
     * @param {Boolean} [options.is_anonymous] True if the poll should be anonymous (default: true).
     * @param {Boolean} [options.allows_multiple_answers] True if multiple answers are allowed (ignored in quiz mode).
     * @param {Integer} [options.correct_option_id] Index of the correct answer (for quiz polls).
     * @param {String} [options.explanation] Text shown for incorrect answers or quiz icon, 0–200 chars.
     * @param {String} [options.explanation_parse_mode] Parse mode for explanation (Markdown, HTML).
     * @param {Array<MessageEntity>} [options.explanation_entities] Alternative to explanation_parse_mode.
     * @param {Integer} [options.open_period] How long the poll stays open (seconds); mutually exclusive with close_date.
     * @param {Integer} [options.close_date] Unix timestamp for when the poll will be closed; mutually exclusive with open_period.
     * @param {Boolean} [options.is_closed] Set to true to immediately close the poll.
     * @param {Boolean} [options.disable_notification] Sends the message silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] Allows paid high-throughput messaging.
     * @param {String} [options.message_effect_id] Optional effect for private chats.
     * @param {ReplyParameters} [options.reply_parameters] Description of the message to reply to.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] UI for reply.
     *
     * @return {Message} Returns the sent poll Message on success.
     */
    static function sendPoll($appId, $chat_id, $question, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        if (!isset($options['options'])) {
            throw new Q_Exception_MissingField(array('field' => 'options'));
        }
        $params = array_merge($options, compact('chat_id', 'question'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendPoll", $params);
    }

    /**
     * Use this method to send an animated emoji that displays a random value.
     * https://core.telegram.org/bots/api#senddice
     *
     * @method sendDice
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.business_connection_id] Unique identifier of the business connection on behalf of which the message will be sent.
     * @param {Integer|String} chat_id Chat ID or @channelusername.
     * @param {String} [options.emoji] Emoji to roll: 🎲, 🎯, 🏀, ⚽, 🎳, or 🎰. Defaults to 🎲.
     * @param {Integer} [options.message_thread_id] Thread ID for forums.
     * @param {Boolean} [options.disable_notification] Sends silently.
     * @param {Boolean} [options.protect_content] Prevents forwarding/saving.
     * @param {Boolean} [options.allow_paid_broadcast] High-volume messaging flag (uses Telegram Stars).
     * @param {String} [options.message_effect_id] Message effect ID (private chats only).
     * @param {ReplyParameters} [options.reply_parameters] Reply descriptor.
     * @param {InlineKeyboardMarkup|ReplyKeyboardMarkup|ReplyKeyboardRemove|ForceReply} [options.reply_markup] Optional reply UI.
     *
     * @return {Message} Returns the sent Message on success.
     */
    static function sendDice($appId, $chat_id, array $options = array())
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id'));
        $params['chat_id'] = $chat_id;

        return self::api($appId, "sendDice", $params);
    }

    /**
     * Use this method to send a game.
     * https://core.telegram.org/bots/api#sendgame
     *
     * @method sendGame
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer} chat_id Unique identifier for the target chat.
     * @param {String} game_short_name Short name of the game, which must be registered via BotFather.
     * @param {InlineKeyboardMarkup} [options.reply_markup] A JSON-serialized object for an inline keyboard.
     *
     * @return {Message} The sent game Message on success.
     */
    static function sendGame($appId, $chat_id, $game_short_name, array $options = array())
    {
        if (!is_int($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact('chat_id', 'game_short_name'));

        return self::api($appId, "sendGame", $params);
    }

    /**
     * Use this method to send invoices.
     * https://core.telegram.org/bots/api#sendinvoice
     *
     * @method sendInvoice
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer} chat_id Unique identifier for the target private chat.
     * @param {String} title Product name.
     * @param {String} description Product description.
     * @param {String} payload Bot-defined invoice payload.
     * @param {String} provider_token Payments provider token (obtained via BotFather).
     * @param {String} currency Three-letter ISO 4217 currency code (e.g., “USD”).
     * @param {array} prices Price breakdown (LabeledPrice array).
     * @param {String} [options.business_connection_id] Business ID on whose behalf the message is sent.
     * @param {String} [options.provider_data] JSON-serialized data for the payment provider.
     * @param {Integer} [options.max_tip_amount] Max tip amount in the smallest currency units.
     * @param {array} [options.suggested_tip_amounts] Array of suggested tip amounts.
     * @param {String} [options.start_parameter] Deep-linking parameter.
     * @param {String} [options.photo_url] URL of product photo.
     * @param {Integer} [options.photo_size] Photo size.
     * @param {Integer} [options.photo_width] Photo width.
     * @param {Integer} [options.photo_height] Photo height.
     * @param {Boolean} [options.need_name] Require user's full name.
     * @param {Boolean} [options.need_phone_number] Require phone number.
     * @param {Boolean} [options.need_email] Require email.
     * @param {Boolean} [options.need_shipping_address] Require shipping.
     * @param {Boolean} [options.send_phone_number_to_provider] Forward phone to provider.
     * @param {Boolean} [options.send_email_to_provider] Forward email to provider.
     * @param {Boolean} [options.is_flexible] Use flexible price.
     * @param {InlineKeyboardMarkup} [options.reply_markup] Inline keyboard.
     *
     * @return {Message} The sent invoice Message on success.
     */
    static function sendInvoice($appId, $chat_id, $title, $description, $payload, $provider_token, $currency, $prices, array $options = array())
    {
        if (!is_int($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array_merge($options, compact(
            'chat_id', 'title', 'description', 'payload',
            'provider_token', 'currency', 'prices'
        ));

        return self::api($appId, "sendInvoice", $params);
    }

    /**
     * Gets a Telegram user's profile photos
     * https://core.telegram.org/bots/api#getuserprofilephotos
     *
     * @method getUserProfilePhotos
     * @static
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $user_id Telegram user ID
     * @param {Integer} [$limit=1] Max number of profile photos to return (max 100)
     * @param {Integer} [$offset=0] Sequential number of the first photo to be returned
     * @return {array|null} Array of photo objects or null if none
     */
    static function getUserProfilePhotos($appId, $user_id, $limit = 1, $offset = 0)
    {
        if (empty($user_id)) {
            throw new Q_Exception_MissingField(['field' => 'user_id']);
        }
        $params = [
            'user_id' => $user_id,
            'limit' => $limit,
            'offset' => $offset
        ];
        $response = self::api($appId, 'getUserProfilePhotos', $params);
        if (!empty($response['result']['total_count'])) {
            return $response['result']['photos'];
        }
        return null;
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
     * Approves a chat join request.
     * The bot must be an admin with can_invite_users permission.
     *
     * @method approveChatJoinRequest
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Unique identifier for the target chat or @channelusername
     * @param {int} $user_id Unique identifier of the user requesting to join
     *
     * @return {bool} True on success
     */
    static function approveChatJoinRequest($appId, $chat_id, $user_id) {
        return self::api($appId, 'approveChatJoinRequest', compact('chat_id', 'user_id'));
    }

    /**
     * Declines a chat join request.
     * The bot must be an admin with can_invite_users permission.
     *
     * @method declineChatJoinRequest
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Unique identifier for the target chat or @channelusername
     * @param {int} $user_id Unique identifier of the user requesting to join
     *
     * @return {bool} True on success
     */
    static function declineChatJoinRequest($appId, $chat_id, $user_id) {
        return self::api($appId, 'declineChatJoinRequest', compact('chat_id', 'user_id'));
    }

    /**
     * Restrict a user's permissions in a supergroup.
     * https://core.telegram.org/bots/api#restrictchatmember
     *
     * @method restrictChatMember
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @channelusername
     * @param {int|string} $user_id User ID of the target user
     * @param {array} [$permissions] Optional. Pass a permissions array (Telegram format).
     * @param {int} [$until_date] Optional. Timestamp until when the restrictions are active
     *
     * @return {bool} Returns true on success.
     */
    static function restrictChatMember($appId, $chat_id, $user_id, array $permissions = array(), $until_date = null)
    {
        if (!is_int($chat_id) && !is_string($chat_id)) {
            throw new Q_Exception_InvalidInput(array('source' => '$chat_id'));
        }
        $params = array(
            'chat_id' => $chat_id,
            'user_id' => $user_id
        );

        if (empty($permissions)) {
            // default to fully muted
            $params['permissions'] = array(
                'can_send_messages' => false,
                'can_send_audios' => false,
                'can_send_documents' => false,
                'can_send_photos' => false,
                'can_send_videos' => false,
                'can_send_video_notes' => false,
                'can_send_voice_notes' => false,
                'can_send_polls' => false,
                'can_send_other_messages' => false,
                'can_add_web_page_previews' => false,
                'can_change_info' => false,
                'can_invite_users' => false,
                'can_pin_messages' => false
            );
        } else {
            $params['permissions'] = $permissions;
        }

        if ($until_date !== null) {
            $params['until_date'] = $until_date;
        }

        return self::api($appId, 'restrictChatMember', $params);
    }

    /**
     * Use this method to ban a user in a group, supergroup or channel.
     * The user will not be able to return unless unbanned.
     *
     * @method banChatMember
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @channelusername
     * @param {int} $user_id User ID to ban
     * @param {array} [$options] Optional: until_date, revoke_messages
     *
     * @return {bool} True on success
     */
    static function banChatMember($appId, $chat_id, $user_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'user_id'));
        return self::api($appId, 'banChatMember', $params);
    }

    /**
     * Unbans a previously banned user from a supergroup or channel.
     *
     * @method unbanChatMember
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @channelusername
     * @param {int} $user_id User ID to unban
     * @param {array} [$options] Optional: only_if_banned
     *
     * @return {bool} True on success
     */
    static function unbanChatMember($appId, $chat_id, $user_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'user_id'));
        return self::api($appId, 'unbanChatMember', $params);
    }

    /**
     * Promotes or demotes a user in a supergroup or channel.
     *
     * @method promoteChatMember
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @channelusername
     * @param {int} $user_id User ID to promote or demote
     * @param {array} [$options] Optional promotion flags
     *
     * @return {bool} True on success
     */
    static function promoteChatMember($appId, $chat_id, $user_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'user_id'));
        return self::api($appId, 'promoteChatMember', $params);
    }

    /**
     * Sets a custom title for an administrator.
     *
     * @method setChatAdministratorCustomTitle
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @supergroupusername
     * @param {int} $user_id User ID of the admin
     * @param {string} $custom_title New custom title (0–16 chars, no emoji)
     *
     * @return {bool} True on success
     */
    static function setChatAdministratorCustomTitle($appId, $chat_id, $user_id, $custom_title) {
        $params = compact('chat_id', 'user_id', 'custom_title');
        return self::api($appId, 'setChatAdministratorCustomTitle', $params);
    }

    /**
     * Bans a channel from sending messages in a group or channel.
     *
     * @method banChatSenderChat
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Target group or channel ID
     * @param {int} $sender_chat_id ID of the sender chat (channel)
     *
     * @return {bool} True on success
     */
    static function banChatSenderChat($appId, $chat_id, $sender_chat_id) {
        return self::api($appId, 'banChatSenderChat', compact('chat_id', 'sender_chat_id'));
    }

    /**
     * Unbans a previously banned channel chat.
     *
     * @method unbanChatSenderChat
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Group or channel ID
     * @param {int} $sender_chat_id Channel chat ID to unban
     *
     * @return {bool} True on success
     */
    static function unbanChatSenderChat($appId, $chat_id, $sender_chat_id) {
        return self::api($appId, 'unbanChatSenderChat', compact('chat_id', 'sender_chat_id'));
    }

    /**
     * Sets default permissions for all members in a chat.
     *
     * @method setChatPermissions
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Chat ID or @supergroupusername
     * @param {array} $permissions JSON-serialized ChatPermissions object
     * @param {array} [$options] Optional: use_independent_chat_permissions
     *
     * @return {bool} True on success
     */
    static function setChatPermissions($appId, $chat_id, array $permissions, array $options = array()) {
        $options['permissions'] = $permissions;
        $params = array_merge($options, compact('chat_id'));
        return self::api($appId, 'setChatPermissions', $params);
    }

    /**
     * Use this method to get the bot's own information, such as its user ID, username, and capabilities.
     * https://core.telegram.org/bots/api#getme
     *
     * @method getMe
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     *
     * @return {array} Returns an associative array with bot information:
     * {
     *   'id' => (int) Telegram bot user ID,
     *   'is_bot' => true,
     *   'first_name' => (string),
     *   'username' => (string),
     *   'can_join_groups' => (bool),
     *   'can_read_all_group_messages' => (bool), // optional
     *   'supports_inline_queries' => (bool),     // optional
     *   ...
     * }
     */
    static function getMe($appId)
    {
        $response = self::api($appId, 'getMe', array());
        return isset($response['result']) ? $response['result'] : array();
    }

        /**
     * Use this method to get the bot's display name and other profile info.
     * https://core.telegram.org/bots/api#getmyname
     *
     * @method getMyName
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string|null} [$language_code] Optional. A two-letter ISO 639-1 language code to localize the result.
     *
     * @return {array} Returns an associative array with keys like 'name', 'language_code'.
     */
    static function getMyName($appId, $language_code = null)
    {
        $params = $language_code ? array('language_code' => $language_code) : array();
        $response = self::api($appId, 'getMyName', $params);
        return $response['result'];
    }

    /**
     * Use this method to change the bot's name. Returns true on success.
     * https://core.telegram.org/bots/api#setmyname
     *
     * @method setMyName
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.name] New bot name; 0-64 characters. Pass an empty string to remove the dedicated name for the given language.
     * @param {String} [options.language_code] A two-letter ISO 639-1 language code. If empty, the name will be shown to all users for whose language there is no dedicated name.
     *
     * @return {Boolean} True on success.
     */
    static function setMyName($appId, array $options = array())
    {
        return self::api($appId, "setMyName", $options);
    }

    /**
     * Use this method to get the bot's description shown in the profile.
     * https://core.telegram.org/bots/api#getmydescription
     *
     * @method getMyDescription
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string|null} [$language_code] Optional. Language code for localization.
     *
     * @return {string} Returns the current description text.
     */
    static function getMyDescription($appId, $language_code = null)
    {
        $params = $language_code ? array('language_code' => $language_code) : array();
        $response = self::api($appId, 'getMyDescription', $params);
        return $response['result']['description'];
    }

    /**
     * Use this method to change the bot's description, shown in the chat with the bot if the chat is empty.
     * https://core.telegram.org/bots/api#setmydescription
     *
     * @method setMyDescription
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {String} [options.description] New bot description; 0-512 characters. Pass an empty string to remove the dedicated description for the given language.
     * @param {String} [options.language_code] A two-letter ISO 639-1 language code. If empty, the description applies to all users without a dedicated language version.
     *
     * @return {Boolean} True on success.
     */
    static function setMyDescription($appId, array $options = array())
    {
        return self::api($appId, "setMyDescription", $options);
    }

    /**
     * Use this method to get the bot's short description.
     * https://core.telegram.org/bots/api#getmyshortdescription
     *
     * @method getMyShortDescription
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string|null} [$language_code] Optional. Language code.
     *
     * @return {string} Returns the current short description.
     */
    static function getMyShortDescription($appId, $language_code = null)
    {
        $params = $language_code ? array('language_code' => $language_code) : array();
        $response = self::api($appId, 'getMyShortDescription', $params);
        return $response['result']['short_description'];
    }

    /**
     * Use this method to set the bot's short description.
     * https://core.telegram.org/bots/api#setmyshortdescription
     *
     * @method setMyShortDescription
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string} $short_description The short description to set.
     * @param {string|null} [$language_code] Optional. Language code.
     *
     * @return {boolean} Returns true on success.
     */
    static function setMyShortDescription($appId, $short_description, $language_code = null)
    {
        $params = array('short_description' => $short_description);
        if ($language_code) {
            $params['language_code'] = $language_code;
        }
        return self::api($appId, 'setMyShortDescription', $params);
    }

    /**
     * Use this method to get the current list of the bot's commands for a given scope and language.
     * https://core.telegram.org/bots/api#getmycommands
     *
     * @method getMyCommands
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {BotCommandScope} [options.scope] A JSON-serialized object describing the user scope. Defaults to BotCommandScopeDefault.
     * @param {String} [options.language_code] Two-letter ISO 639-1 language code or empty string.
     *
     * @return {Array<BotCommand>} Returns an array of BotCommand objects. Returns an empty list if no commands are set.
     */
    static function getMyCommands($appId, array $options = array())
    {
        return self::api($appId, "getMyCommands", $options);
    }

    /**
     * Use this method to change the list of the bot's commands.
     * See https://core.telegram.org/bots/api#setmycommands for more.
     *
     * @method setMyCommands
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Array<BotCommand>} $commands A JSON-serialized list of bot commands to set; at most 100 commands allowed.
     * @param {BotCommandScope} [options.scope] A JSON-serialized object describing the user scope the commands apply to. Defaults to BotCommandScopeDefault.
     * @param {String} [options.language_code] Two-letter ISO 639-1 language code. If empty, commands apply to all users in the given scope without dedicated commands.
     *
     * @return {Boolean} True on success.
     */
    static function setMyCommands($appId, array $commands, array $options = array())
    {
        $params = array_merge($options, array('commands' => $commands));
        return self::api($appId, "setMyCommands", $params);
    }

    /**
     * Use this method to delete the list of the bot's commands for a given scope and language.
     * https://core.telegram.org/bots/api#deletemycommands
     *
     * @method deleteMyCommands
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {BotCommandScope} [options.scope] A JSON-serialized object describing the user scope. Defaults to BotCommandScopeDefault.
     * @param {String} [options.language_code] Two-letter ISO 639-1 language code. If empty, deletion applies to all users from the given scope without a dedicated language.
     *
     * @return {Boolean} True on success.
     */
    static function deleteMyCommands($appId, array $options = array())
    {
        return self::api($appId, "deleteMyCommands", $options);
    }

    /**
     * Use this method to get the current default administrator rights of the bot.
     * https://core.telegram.org/bots/api#getmydefaultadministratorrights
     *
     * @method getMyDefaultAdministratorRights
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {bool} [$for_channels=false] Whether to get rights for channels instead of groups.
     *
     * @return {array} Returns the default admin rights.
     */
    static function getMyDefaultAdministratorRights($appId, $for_channels = false)
    {
        $params = $for_channels ? array('for_channels' => true) : array();
        $response = self::api($appId, 'getMyDefaultAdministratorRights', $params);
        return $response['result'];
    }

    /**
     * Use this method to change the default administrator rights requested by the bot when added as admin to groups or channels.
     * https://core.telegram.org/bots/api#setmydefaultadministratorrights
     *
     * @method setMyDefaultAdministratorRights
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {ChatAdministratorRights} [options.rights] A JSON-serialized object describing new default admin rights. If not specified, rights will be cleared.
     * @param {Boolean} [options.for_channels] Pass true to change default admin rights in channels; otherwise applies to groups/supergroups.
     *
     * @return {Boolean} True on success.
     */
    static function setMyDefaultAdministratorRights($appId, array $options = array())
    {
        return self::api($appId, "setMyDefaultAdministratorRights", $options);
    }

    /**
     * Use this method to get the current value of the bot's menu button in a private chat, or the default menu button.
     * https://core.telegram.org/bots/api#getchatmenubutton
     *
     * @method getChatMenuButton
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer} [options.chat_id] Unique identifier for the target private chat. If not specified, the default bot's menu button will be returned.
     *
     * @return {MenuButton} Returns the current menu button on success.
     */
    static function getChatMenuButton($appId, array $options = array())
    {
        return self::api($appId, "getChatMenuButton", $options);
    }

    /**
     * Use this method to change the bot's menu button in a private chat, or the default menu button.
     * https://core.telegram.org/bots/api#setchatmenubutton
     *
     * @method setChatMenuButton
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer} [options.chat_id] Unique identifier for the target private chat. If not specified, the default bot's menu button will be changed.
     * @param {MenuButton} [options.menu_button] A JSON-serialized object for the bot's new menu button. Defaults to MenuButtonDefault.
     *
     * @return {Boolean} True on success.
     */
    static function setChatMenuButton($appId, array $options = array())
    {
        return self::api($appId, "setChatMenuButton", $options);
    }
    
    /**
     * Use this method to set a new profile photo for the chat.
     * https://core.telegram.org/bots/api#setchatphoto
     *
     * @method setChatPhoto
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or channel
     * @param {InputFile} $photo New chat photo, uploaded using multipart/form-data
     *
     * @return {Boolean} Returns True on success.
     */
    static function setChatPhoto($appId, $chat_id, $photo)
    {
        $params = array('chat_id' => $chat_id, 'photo' => $photo);
        return self::api($appId, "setChatPhoto", $params);
    }

    /**
     * Use this method to delete a chat photo.
     * https://core.telegram.org/bots/api#deletechatphoto
     *
     * @method deleteChatPhoto
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or channel
     *
     * @return {Boolean} Returns True on success.
     */
    static function deleteChatPhoto($appId, $chat_id)
    {
        $params = array('chat_id' => $chat_id);
        return self::api($appId, "deleteChatPhoto", $params);
    }

    /**
     * Use this method to change the title of a chat.
     * https://core.telegram.org/bots/api#setchattitle
     *
     * @method setChatTitle
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or channel
     * @param {string} $title New chat title, 1-128 characters
     *
     * @return {Boolean} Returns True on success.
     */
    static function setChatTitle($appId, $chat_id, $title)
    {
        $params = array('chat_id' => $chat_id, 'title' => $title);
        return self::api($appId, "setChatTitle", $params);
    }

    /**
     * Use this method to change the description of a chat.
     * https://core.telegram.org/bots/api#setchatdescription
     *
     * @method setChatDescription
     * @static
     *
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {Integer|String} $chat_id Unique identifier for the target chat or channel
     * @param {string} $description New chat description, 0-255 characters
     *
     * @return {Boolean} Returns True on success.
     */
    static function setChatDescription($appId, $chat_id, $description)
    {
        $params = array('chat_id' => $chat_id, 'description' => $description);
        return self::api($appId, "setChatDescription", $params);
    }

    /**
     * Use this method to add a message to the list of pinned messages in a chat.
     * https://core.telegram.org/bots/api#pinchatmessage
     *
     * @method pinChatMessage
     * @static
     * @param {string} $appId The appId under Users/apps/telegram config
     * @param {string|int} $chat_id Unique identifier for the target chat or username (e.g. @channelusername)
     * @param {int} $message_id Identifier of a message to pin
     * @param {array} $options Optional parameters: business_connection_id, disable_notification
     * @return {bool} True on success
     */
    static function pinChatMessage($appId, $chat_id, $message_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'message_id'));
        return self::api($appId, 'pinChatMessage', $params);
    }

    /**
     * Use this method to remove a pinned message.
     * https://core.telegram.org/bots/api#unpinchatmessage
     *
     * @method unpinChatMessage
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {array} $options Optional parameters: message_id, business_connection_id
     * @return {bool}
     */
    static function unpinChatMessage($appId, $chat_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id'));
        return self::api($appId, 'unpinChatMessage', $params);
    }

    /**
     * Use this method to clear the list of pinned messages.
     * https://core.telegram.org/bots/api#unpinallchatmessages
     *
     * @method unpinAllChatMessages
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function unpinAllChatMessages($appId, $chat_id) {
        $params = array('chat_id' => $chat_id);
        return self::api($appId, 'unpinAllChatMessages', $params);
    }

    /**
     * Use this method for your bot to leave a group, supergroup or channel.
     * https://core.telegram.org/bots/api#leavechat
     *
     * @method leaveChat
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function leaveChat($appId, $chat_id) {
        return self::api($appId, 'leaveChat', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to get info about a chat.
     * https://core.telegram.org/bots/api#getchat
     *
     * @method getChat
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {array} ChatFullInfo
     */
    static function getChat($appId, $chat_id) {
        return self::api($appId, 'getChat', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to get a list of chat admins.
     * https://core.telegram.org/bots/api#getchatadministrators
     *
     * @method getChatAdministrators
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {array} Array of ChatMember objects
     */
    static function getChatAdministrators($appId, $chat_id) {
        return self::api($appId, 'getChatAdministrators', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to get the number of chat members.
     * https://core.telegram.org/bots/api#getchatmembercount
     *
     * @method getChatMemberCount
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {int}
     */
    static function getChatMemberCount($appId, $chat_id) {
        return self::api($appId, 'getChatMemberCount', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to get info about a chat member.
     * https://core.telegram.org/bots/api#getchatmember
     *
     * @method getChatMember
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $user_id
     * @return {array} ChatMember object
     */
    static function getChatMember($appId, $chat_id, $user_id) {
        return self::api($appId, 'getChatMember', array('chat_id' => $chat_id, 'user_id' => $user_id));
    }

    /**
     * Use this method to set a new group sticker set for a supergroup.
     * https://core.telegram.org/bots/api#setchatstickerset
     *
     * @method setChatStickerSet
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {string} $sticker_set_name
     * @return {bool}
     */
    static function setChatStickerSet($appId, $chat_id, $sticker_set_name) {
        return self::api($appId, 'setChatStickerSet', array(
            'chat_id' => $chat_id,
            'sticker_set_name' => $sticker_set_name
        ));
    }

    /**
     * Use this method to delete a group sticker set from a supergroup.
     * https://core.telegram.org/bots/api#deletechatstickerset
     *
     * @method deleteChatStickerSet
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function deleteChatStickerSet($appId, $chat_id) {
        return self::api($appId, 'deleteChatStickerSet', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to get custom emoji stickers for forum topics.
     * https://core.telegram.org/bots/api#getforumtopiciconstickers
     *
     * @method getForumTopicIconStickers
     * @static
     * @param {string} $appId
     * @return {array} Array of Sticker objects
     */
    static function getForumTopicIconStickers($appId) {
        return self::api($appId, 'getForumTopicIconStickers');
    }

    /**
     * Use this method to create a topic in a forum supergroup chat.
     * https://core.telegram.org/bots/api#createforumtopic
     *
     * @method createForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {string} $name
     * @param {array} $options Optional parameters: icon_color, icon_custom_emoji_id
     * @return {array} ForumTopic object
     */
    static function createForumTopic($appId, $chat_id, $name, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'name'));
        return self::api($appId, 'createForumTopic', $params);
    }

    /**
     * Use this method to edit a topic's name and icon.
     * https://core.telegram.org/bots/api#editforumtopic
     *
     * @method editForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $message_thread_id
     * @param {array} $options Optional: name, icon_custom_emoji_id
     * @return {bool}
     */
    static function editForumTopic($appId, $chat_id, $message_thread_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'message_thread_id'));
        return self::api($appId, 'editForumTopic', $params);
    }

    /**
     * Use this method to close a forum topic.
     * https://core.telegram.org/bots/api#closeforumtopic
     *
     * @method closeForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $message_thread_id
     * @return {bool}
     */
    static function closeForumTopic($appId, $chat_id, $message_thread_id) {
        return self::api($appId, 'closeForumTopic', compact('chat_id', 'message_thread_id'));
    }

    /**
     * Use this method to reopen a closed forum topic.
     * https://core.telegram.org/bots/api#reopenforumtopic
     *
     * @method reopenForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $message_thread_id
     * @return {bool}
     */
    static function reopenForumTopic($appId, $chat_id, $message_thread_id) {
        return self::api($appId, 'reopenForumTopic', compact('chat_id', 'message_thread_id'));
    }

    /**
     * Use this method to delete a forum topic and all its messages.
     * https://core.telegram.org/bots/api#deleteforumtopic
     *
     * @method deleteForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $message_thread_id
     * @return {bool}
     */
    static function deleteForumTopic($appId, $chat_id, $message_thread_id) {
        return self::api($appId, 'deleteForumTopic', compact('chat_id', 'message_thread_id'));
    }

    /**
     * Use this method to unpin all messages in a forum topic.
     * https://core.telegram.org/bots/api#unpinallforumtopicmessages
     *
     * @method unpinAllForumTopicMessages
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {int} $message_thread_id
     * @return {bool}
     */
    static function unpinAllForumTopicMessages($appId, $chat_id, $message_thread_id) {
        return self::api($appId, 'unpinAllForumTopicMessages', compact('chat_id', 'message_thread_id'));
    }

    /**
     * Use this method to edit the 'General' topic name.
     * https://core.telegram.org/bots/api#editgeneralforumtopic
     *
     * @method editGeneralForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @param {string} $name
     * @return {bool}
     */
    static function editGeneralForumTopic($appId, $chat_id, $name) {
        return self::api($appId, 'editGeneralForumTopic', compact('chat_id', 'name'));
    }

    /**
     * Use this method to close the 'General' forum topic.
     * https://core.telegram.org/bots/api#closegeneralforumtopic
     *
     * @method closeGeneralForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function closeGeneralForumTopic($appId, $chat_id) {
        return self::api($appId, 'closeGeneralForumTopic', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to reopen the 'General' forum topic.
     * https://core.telegram.org/bots/api#reopengeneralforumtopic
     *
     * @method reopenGeneralForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function reopenGeneralForumTopic($appId, $chat_id) {
        return self::api($appId, 'reopenGeneralForumTopic', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to hide the 'General' topic.
     * https://core.telegram.org/bots/api#hidegeneralforumtopic
     *
     * @method hideGeneralForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function hideGeneralForumTopic($appId, $chat_id) {
        return self::api($appId, 'hideGeneralForumTopic', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to unhide the 'General' topic.
     * https://core.telegram.org/bots/api#unhidegeneralforumtopic
     *
     * @method unhideGeneralForumTopic
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function unhideGeneralForumTopic($appId, $chat_id) {
        return self::api($appId, 'unhideGeneralForumTopic', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to unpin all messages in the General topic.
     * https://core.telegram.org/bots/api#unpinallgeneralforumtopicmessages
     *
     * @method unpinAllGeneralForumTopicMessages
     * @static
     * @param {string} $appId
     * @param {string|int} $chat_id
     * @return {bool}
     */
    static function unpinAllGeneralForumTopicMessages($appId, $chat_id) {
        return self::api($appId, 'unpinAllGeneralForumTopicMessages', array('chat_id' => $chat_id));
    }

    /**
     * Use this method to forward messages of any kind.
     * Service messages and messages with protected content can't be forwarded.
     * On success, the sent Message is returned.
     *
     * @method forwardMessage
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Target chat ID or @channelusername
     * @param {int|string} $from_chat_id Chat ID to forward from
     * @param {int} $message_id Message ID in the source chat
     * @param {array} [$options] Optional: message_thread_id, video_start_timestamp, disable_notification, protect_content
     *
     * @return {Message} The forwarded message on success
     */
    static function forwardMessage($appId, $chat_id, $from_chat_id, $message_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'from_chat_id', 'message_id'));
        return self::api($appId, 'forwardMessage', $params);
    }

    /**
     * Use this method to forward multiple messages of any kind.
     * Service messages and messages with protected content can't be forwarded.
     * Album grouping is kept. Skips any messages it can't forward.
     *
     * @method forwardMessages
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Target chat ID or @channelusername
     * @param {int|string} $from_chat_id Source chat ID or @channelusername
     * @param {array} $message_ids Array of message IDs (1–100), increasing order
     * @param {array} [$options] Optional: message_thread_id, disable_notification, protect_content
     *
     * @return {array<MessageId>} Array of forwarded message IDs
     */
    static function forwardMessages($appId, $chat_id, $from_chat_id, array $message_ids, array $options = array()) {
        $options['message_ids'] = $message_ids;
        $params = array_merge($options, compact('chat_id', 'from_chat_id'));
        return self::api($appId, 'forwardMessages', $params);
    }

    /**
     * Use this method to copy messages of any kind without link to original.
     * Returns the MessageId of the sent message on success.
     *
     * @method copyMessage
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Target chat ID or @channelusername
     * @param {int|string} $from_chat_id Source chat ID
     * @param {int} $message_id Message ID in the source chat
     * @param {array} [$options] Optional: message_thread_id, video_start_timestamp, caption, parse_mode, caption_entities, show_caption_above_media, disable_notification, protect_content, allow_paid_broadcast, reply_parameters, reply_markup
     *
     * @return {MessageId} ID of copied message
     */
    static function copyMessage($appId, $chat_id, $from_chat_id, $message_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'from_chat_id', 'message_id'));
        return self::api($appId, 'copyMessage', $params);
    }

    /**
     * Use this method to copy multiple messages of any kind.
     * Skips any message it can't copy. Album grouping is preserved.
     *
     * @method copyMessages
     * @static
     *
     * @param {string} $appId App ID under Users/apps/telegram config
     * @param {int|string} $chat_id Target chat ID or @channelusername
     * @param {int|string} $from_chat_id Source chat ID
     * @param {array} $message_ids Array of message IDs (1–100), strictly increasing
     * @param {array} [$options] Optional: message_thread_id, disable_notification, protect_content, remove_caption
     *
     * @return {array<MessageId>} Array of copied message IDs
     */
    static function copyMessages($appId, $chat_id, $from_chat_id, array $message_ids, array $options = array()) {
        $options['message_ids'] = $message_ids;
        $params = array_merge($options, compact('chat_id', 'from_chat_id'));
        return self::api($appId, 'copyMessages', $params);
    }

    /**
     * Use this method to edit text and game messages.
     * https://core.telegram.org/bots/api#editmessagetext
     *
     * @method editMessageText
     * @static
     * @param {string} $appId
     * @param {array} $params Required parameters: chat_id/message_id or inline_message_id, text. Optional: parse_mode, entities, link_preview_options, reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function editMessageText($appId, array $params) {
        return self::api($appId, 'editMessageText', $params);
    }

    /**
     * Use this method to edit captions of messages.
     * https://core.telegram.org/bots/api#editmessagecaption
     *
     * @method editMessageCaption
     * @static
     * @param {string} $appId
     * @param {array} $params Required parameters: chat_id/message_id or inline_message_id. Optional: caption, parse_mode, caption_entities, show_caption_above_media, reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function editMessageCaption($appId, array $params) {
        return self::api($appId, 'editMessageCaption', $params);
    }

    /**
     * Use this method to edit media in messages.
     * https://core.telegram.org/bots/api#editmessagemedia
     *
     * @method editMessageMedia
     * @static
     * @param {string} $appId
     * @param {array} $params Required: media and (chat_id/message_id or inline_message_id). Optional: reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function editMessageMedia($appId, array $params) {
        return self::api($appId, 'editMessageMedia', $params);
    }

    /**
     * Use this method to edit live location messages.
     * https://core.telegram.org/bots/api#editmessagelivelocation
     *
     * @method editMessageLiveLocation
     * @static
     * @param {string} $appId
     * @param {array} $params Required: latitude, longitude, and (chat_id/message_id or inline_message_id). Optional: live_period, heading, horizontal_accuracy, proximity_alert_radius, reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function editMessageLiveLocation($appId, array $params) {
        return self::api($appId, 'editMessageLiveLocation', $params);
    }

    /**
     * Use this method to stop a live location message.
     * https://core.telegram.org/bots/api#stopmessagelivelocation
     *
     * @method stopMessageLiveLocation
     * @static
     * @param {string} $appId
     * @param {array} $params Required: chat_id/message_id or inline_message_id. Optional: reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function stopMessageLiveLocation($appId, array $params) {
        return self::api($appId, 'stopMessageLiveLocation', $params);
    }

    /**
     * Use this method to edit only the reply markup of a message.
     * https://core.telegram.org/bots/api#editmessagereplymarkup
     *
     * @method editMessageReplyMarkup
     * @static
     * @param {string} $appId
     * @param {array} $params Required: chat_id/message_id or inline_message_id. Optional: reply_markup
     * @return {array|bool} Message on success, or True if inline
     */
    static function editMessageReplyMarkup($appId, array $params) {
        return self::api($appId, 'editMessageReplyMarkup', $params);
    }

    /**
     * Use this method to stop a poll.
     * https://core.telegram.org/bots/api#stoppoll
     *
     * @method stopPoll
     * @static
     * @param {string} $appId
     * @param {int|string} $chat_id
     * @param {int} $message_id
     * @param {array} $options Optional: reply_markup
     * @return {array} Poll
     */
    static function stopPoll($appId, $chat_id, $message_id, array $options = array()) {
        $params = array_merge($options, compact('chat_id', 'message_id'));
        return self::api($appId, 'stopPoll', $params);
    }

    /**
     * Use this method to delete a message.
     * https://core.telegram.org/bots/api#deletemessage
     *
     * @method deleteMessage
     * @static
     * @param {string} $appId
     * @param {int|string} $chat_id
     * @param {int} $message_id
     * @return {bool}
     */
    static function deleteMessage($appId, $chat_id, $message_id) {
        return self::api($appId, 'deleteMessage', compact('chat_id', 'message_id'));
    }

    /**
     * Use this method to delete multiple messages.
     * https://core.telegram.org/bots/api#deletemessages
     *
     * @method deleteMessages
     * @static
     * @param {string} $appId
     * @param {int|string} $chat_id
     * @param {array} $message_ids List of message IDs
     * @return {bool}
     */
    static function deleteMessages($appId, $chat_id, array $message_ids) {
        return self::api($appId, 'deleteMessages', compact('chat_id', 'message_ids'));
    }

    /**
     * Use this method to verify a user on behalf of an organization.
     * https://core.telegram.org/bots/api#verifyuser
     *
     * @method verifyUser
     * @static
     * @param {string} $appId
     * @param {int} $user_id
     * @param {string|null} $custom_description Optional custom description
     * @return {bool}
     */
    static function verifyUser($appId, $user_id, $custom_description = null) {
        $params = compact('user_id');
        if (!is_null($custom_description)) $params['custom_description'] = $custom_description;
        return self::api($appId, 'verifyUser', $params);
    }

    /**
     * Use this method to verify a chat on behalf of an organization.
     * https://core.telegram.org/bots/api#verifychat
     *
     * @method verifyChat
     * @static
     * @param {string} $appId
     * @param {int|string} $chat_id
     * @param {string|null} $custom_description Optional custom description
     * @return {bool}
     */
    static function verifyChat($appId, $chat_id, $custom_description = null) {
        $params = compact('chat_id');
        if (!is_null($custom_description)) $params['custom_description'] = $custom_description;
        return self::api($appId, 'verifyChat', $params);
    }

    /**
     * Use this method to remove verification from a user.
     * https://core.telegram.org/bots/api#removeuserverification
     *
     * @method removeUserVerification
     * @static
     * @param {string} $appId
     * @param {int} $user_id
     * @return {bool}
     */
    static function removeUserVerification($appId, $user_id) {
        return self::api($appId, 'removeUserVerification', compact('user_id'));
    }

    /**
     * Use this method to remove verification from a chat.
     * https://core.telegram.org/bots/api#removechatverification
     *
     * @method removeChatVerification
     * @static
     * @param {string} $appId
     * @param {int|string} $chat_id
     * @return {bool}
     */
    static function removeChatVerification($appId, $chat_id) {
        return self::api($appId, 'removeChatVerification', compact('chat_id'));
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
        if (empty($info['token'])) {
            if ($aifu = Q_Config::get('Users', 'apps', 'telegram', '*', 'appIdForAuth', null)) {
                $appId2 = null;
                $apps = Q_Config::get('Users', 'apps', 'telegram', array());
                foreach ($apps as $k => $v) {
                    if ($k !== '*') {
                        $appId2 = $k;
                        break;
                    }
                }
                if ($appId2) {
                    list($appId2, $info) = Users::appInfo("telegram", $appId2, true);
                }
            }
            if (empty($info['token'])) {
                throw new Q_Exception_MissingConfig(array('fieldpath' => "Users/apps/telegram/$appId/token"));
            }
        }
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
    static function api($appId, $methodName, array $params, $headers = array())
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
        ), array()/*curl_opts*/, $headers, 30, false);

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