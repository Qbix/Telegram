# Telegram Plugin

Telegram Bot API integration, user authentication via Telegram Login Widget and Mini Apps, chat syndication, and push notification delivery for the Qbix platform. The plugin bridges Telegram bots and Qbix's user/stream systems — Telegram users become Qbix users, Telegram group/channel chats become `Telegram/chat` streams, and the Streams notification system can deliver messages as Telegram DMs.

## Core Concepts

### Authentication

Users authenticate via Telegram's Login Widget or Mini App WebView. `Users_ExternalFrom_Telegram::authenticate()` verifies the data signature using HMAC-SHA256 (token + "WebAppData" key), opens a deterministic PHP session tied to the Telegram userId + appId, and returns an `ExternalFrom` object that the Users plugin uses to find or create the Qbix user. The deterministic session ID means the same Telegram user always gets the same server session, which is important for bot webhooks and Mini App WebViews where cookies aren't available.

Data verification: `Telegram::verifyData($appId, $data)` checks the `hash` field against an HMAC computed from all other fields sorted alphabetically and joined with newlines, using `hash_hmac('sha256', token, 'WebAppData')` as the key.

### Bot API

`Telegram_Bot` wraps the entire Telegram Bot API as static PHP methods. Every method takes `$appId` as the first argument (resolved to a bot token via `Users/apps/telegram/{appId}/token` config) and calls `Telegram_Bot::api($appId, $methodName, $params)`.

Messaging: `sendMessage`, `sendPhoto`, `sendVideo`, `sendAudio`, `sendVoice`, `sendDocument`, `sendAnimation`, `sendSticker`, `sendMediaGroup`, `sendLocation`, `sendVenue`, `sendContact`, `sendPoll`, `sendDice`, `sendGame`, `sendInvoice`, `sendVideoNote`, `sendChatAction`.

Message management: `editMessageText`, `editMessageCaption`, `editMessageMedia`, `editMessageReplyMarkup`, `editMessageLiveLocation`, `stopMessageLiveLocation`, `forwardMessage`, `forwardMessages`, `copyMessage`, `copyMessages`, `deleteMessage`, `deleteMessages`, `stopPoll`.

Chat management: `getChat`, `getChatAdministrators`, `getChatMemberCount`, `getChatMember`, `setChatPhoto`, `deleteChatPhoto`, `setChatTitle`, `setChatDescription`, `setChatPermissions`, `setChatStickerSet`, `deleteChatStickerSet`, `pinChatMessage`, `unpinChatMessage`, `unpinAllChatMessages`, `leaveChat`.

Member management: `banChatMember`, `unbanChatMember`, `promoteChatMember`, `restrictChatMember`, `setChatAdministratorCustomTitle`, `banChatSenderChat`, `unbanChatSenderChat`, `approveChatJoinRequest`, `declineChatJoinRequest`.

Forum topics: `createForumTopic`, `editForumTopic`, `closeForumTopic`, `reopenForumTopic`, `deleteForumTopic`, `unpinAllForumTopicMessages`, `editGeneralForumTopic`, `closeGeneralForumTopic`, `reopenGeneralForumTopic`, `hideGeneralForumTopic`, `unhideGeneralForumTopic`, `unpinAllGeneralForumTopicMessages`, `getForumTopicIconStickers`.

Bot profile: `getMe`, `getMyName`, `setMyName`, `getMyDescription`, `setMyDescription`, `getMyShortDescription`, `setMyShortDescription`, `getMyCommands`, `setMyCommands`, `deleteMyCommands`, `getMyDefaultAdministratorRights`, `setMyDefaultAdministratorRights`, `getChatMenuButton`, `setChatMenuButton`.

Verification: `verifyUser`, `verifyChat`, `removeUserVerification`, `removeChatVerification`.

Callbacks: `answerCallbackQuery`, `answerInlineQuery`.

Webhooks: `setWebhook`, `deleteWebhook`, `getUpdates`.

Utilities: `getUserProfilePhotos`, `getFileURL`, `getUpdateType`.

### Chat Syndication

When the bot is added to a Telegram group/channel, the plugin creates a `Telegram/chat` stream that mirrors messages bidirectionally. Configuration in `Telegram.syndicate.chatTypes` controls which chat types are syndicated (private, group, supergroup, channel).

### Push Notifications

`Users_ExternalFrom_Telegram::handlePushNotification()` delivers Qbix notifications as Telegram DMs via `Telegram_Bot::sendMessage()`. Handles permanent rejections (blocked/deactivated/forbidden) and rate limiting (429) with appropriate error flags.

### User Import

`Telegram::import()` maps Telegram fields to Qbix user fields per `Users.import.telegram` config: first_name, last_name, username, language_code → preferredLanguage, bio. Profile photos are imported via `Telegram::userIcon()` using `getUserProfilePhotos` + `getFileURL`.

`Telegram::futureUser()` creates or retrieves a Qbix user from a Telegram user object, setting the platform to `telegram_all` (because Telegram xids are global, not per-app).

### Registration Date Estimation

`Telegram::approximateRegistrationDate($telegramUserId)` interpolates between known (userId → date) pairs stored in `config/dates.json` to estimate when a Telegram account was created. Used for minimum-age enforcement via `Users/apps/telegram/{appId}/authentication/minAgeInDays`.

### Webhook Dispatcher

`Telegram_Dispatcher` receives webhook updates from Telegram, determines the update type via `Telegram_Bot::getUpdateType()`, authenticates the user, and dispatches to the appropriate handler (e.g. `Telegram/telegram/message/response`, `Telegram/telegram/callback_query/response`). The `secret_token` from `setWebhook` is verified in the `X-Telegram-Bot-Api-Secret-Token` header.

### Intent-Based Deep Linking

The `/start` command parameter supports two formats: `intent-{token}` (accepts a Users_Intent, enabling cross-platform session transfer) and `invite-{token}` (accepts a Streams_Invite). This enables flows like "click a link on the web → authenticate in Telegram → continue with the same session."

## Stream Types

| Type | Purpose |
|---|---|
| `Telegram/chat` | Synced Telegram group/channel chat |

## User Streams

`Telegram/chats` — category of Telegram chats the user participates in. User field mappings: `Streams/user/username` ← telegram username, `Streams/user/firstName` ← first_name, `Streams/user/lastName` ← last_name, `Streams/greeting/{communityId}` ← bio.

## Configuration

```json
{
    "Users": {
        "apps": {
            "telegram": {
                "MyBot": {
                    "appId": "MyBot",
                    "token": "BOT_TOKEN_FROM_BOTFATHER",
                    "secret": "OPTIONAL_SECRET"
                }
            }
        }
    },
    "Telegram": {
        "syndicate": {
            "chatTypes": { "private": false, "group": true, "supergroup": true, "channel": true }
        }
    }
}
```