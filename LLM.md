# Telegram Plugin — LLM Coding Primer

Supplement to Q Framework and Users primers. Covers Bot API, authentication,
chat syndication, and push notifications via Telegram.

---

## 1. Sending Messages

```php
$appId = 'MyBot'; // matches Users/apps/telegram/{appId}

// Text message
Telegram_Bot::sendMessage($appId, $chatId, 'Hello!', array(
    'parse_mode'   => 'MarkdownV2',  // or 'HTML'
    'reply_markup' => array(          // inline keyboard
        'inline_keyboard' => array(
            array(array('text' => 'Click me', 'callback_data' => 'btn1'))
        )
    )
));

// Photo, video, document, audio, voice, animation, sticker
Telegram_Bot::sendPhoto($appId, $chatId, $photoUrlOrFileId, array('caption' => 'Look!'));
Telegram_Bot::sendVideo($appId, $chatId, $videoUrlOrPath);
Telegram_Bot::sendDocument($appId, $chatId, $fileUrlOrPath);
Telegram_Bot::sendLocation($appId, $chatId, $lat, $lng);
Telegram_Bot::sendVenue($appId, $chatId, $lat, $lng, 'Place', '123 Main St');
Telegram_Bot::sendContact($appId, $chatId, '+1234567890', 'John');

// Media group (album)
Telegram_Bot::sendMediaGroup($appId, $chatId, array(
    array('type' => 'photo', 'media' => $url1),
    array('type' => 'photo', 'media' => $url2)
));

// Chat action (typing indicator)
Telegram_Bot::sendChatAction($appId, $chatId, 'typing');

// Edit/delete
Telegram_Bot::editMessageText($appId, 'Updated text', array(
    'chat_id' => $chatId, 'message_id' => $msgId
));
Telegram_Bot::deleteMessage($appId, $chatId, $msgId);

// Forward/copy
Telegram_Bot::forwardMessage($appId, $toChatId, $fromChatId, $msgId);
Telegram_Bot::copyMessage($appId, $toChatId, $fromChatId, $msgId);
```

---

## 2. Authentication

```php
// Server-side: verify Telegram login data
$isValid = Telegram::verifyData($appId, $_GET);  // query string from Login Widget

// Authenticate and get/create Qbix user
$ef = Users_ExternalFrom_Telegram::authenticate($appId);
if ($ef) {
    // $ef->xid = Telegram user ID
    // Session is deterministic: same Telegram user → same PHP session
    $user = Users::authenticate('telegram', $appId);
}

// Create a future user from Telegram user data (for invites, etc.)
$user = Telegram::futureUser($appId, $telegramUserArray, $status, $inserted);

// Deterministic session ID (useful for bot webhooks)
$sessionId = Telegram::sessionId($appId, $telegramUserId);

// Estimate registration date (for age checks)
$date = Telegram::approximateRegistrationDate($telegramUserId);
```

---

## 3. Webhook Setup

```php
// Set webhook for your bot
Telegram_Bot::setWebhook($appId,
    '{{baseUrl}}/Telegram/telegram',   // URL receives updates
    array(
        'secret_token'    => Telegram::secretToken($appId),
        'allowed_updates' => array('message', 'callback_query', 'my_chat_member'),
        'max_connections'  => 40
    )
);

// Remove webhook
Telegram_Bot::deleteWebhook($appId, array('drop_pending_updates' => true));

// Poll for updates (alternative to webhook)
$updates = Telegram_Bot::getUpdates($appId, array(
    'offset'  => $lastUpdateId + 1,
    'limit'   => 100,
    'timeout' => 30
));

// Determine update type
$type = Telegram_Bot::getUpdateType($update);
// Returns: 'message', 'callback_query', 'my_chat_member', etc.
```

---

## 4. Chat & Member Management

```php
// Get chat info
$chat = Telegram_Bot::getChat($appId, $chatId);

// Member management
Telegram_Bot::banChatMember($appId, $chatId, $userId);
Telegram_Bot::unbanChatMember($appId, $chatId, $userId);
Telegram_Bot::restrictChatMember($appId, $chatId, $userId, array(
    'can_send_messages' => false
), $untilDate);
Telegram_Bot::promoteChatMember($appId, $chatId, $userId, array(
    'can_delete_messages' => true
));

// Join request handling
Telegram_Bot::approveChatJoinRequest($appId, $chatId, $userId);
Telegram_Bot::declineChatJoinRequest($appId, $chatId, $userId);

// Forum topics (supergroups)
Telegram_Bot::createForumTopic($appId, $chatId, 'Topic Name');
Telegram_Bot::closeForumTopic($appId, $chatId, $threadId);

// Bot profile
$me = Telegram_Bot::getMe($appId);  // bot info
Telegram_Bot::setMyCommands($appId, array(
    array('command' => 'start', 'description' => 'Start the bot'),
    array('command' => 'help',  'description' => 'Show help')
));
```

---

## 5. Bot User Registration

```php
// Register the bot itself as a Qbix Users_User
$botUser = Telegram_Bot::registerUser($appId);
// Creates Users_User with id "Telegram.$appId"
// Creates ExternalFrom/ExternalTo/Identify rows
// Imports bot icon if configured

// Get existing bot user
$botUser = Telegram_Bot::getUser($appId);
```

---

## 6. Push Notifications

```php
// Delivered automatically by Streams notification system when
// user has a Users_ExternalFrom_Telegram row.
// Notification → Telegram_Bot::sendMessage($appId, $xid, $text)
// Handles: forbidden/blocked/deactivated (rejected=true)
//          429/rate-limited (rateLimited=true)

// Notification delivery rules configured in plugin.json:
// Streams.rules.deliver.prepend = "telegram"
// Streams.rules.invited.prepend = "telegram"
// Streams.rules.mentioned.prepend = "telegram"
```

---

## 7. Common Mistakes

| Wrong | Right |
|-------|-------|
| Using numeric Telegram appId as the `$appId` param | `$appId` is the config key under `Users/apps/telegram/`, not the numeric bot ID |
| Calling `sendMessage` with unescaped MarkdownV2 | Use `Q_Markdown::cleanup()` or pass `'parse_mode' => 'HTML'` |
| Setting webhook without `secret_token` | Always pass `Telegram::secretToken($appId)` for security |
| Creating Telegram users with app-specific appId | Telegram xids are global; use `telegram_all` (handled by `futureUser()`) |
| Trying to send to a user who blocked the bot | Check for `$e->rejected = true` in the exception handler |