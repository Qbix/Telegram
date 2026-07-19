/**
 * Class for using Telegram Bots
 *
 * @module Telegram
 */
var Q = require('Q');
var Telegram = Q.require('Telegram');

/**
 * Telegram Bot API wrapper (client-side or server-side)
 * Mirrors PHP Telegram class (sendMessage, sendPhoto, etc.)
 * @class Telegram.Bot
 * @namespace Q.plugins.Telegram
 */
Telegram.Bot = {

	/**
	 * Get bot information
	 * @method getMyInfo
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @returns {Object} { id: (int) Telegram bot user ID, is_bot: true, username: (string) }
	 * @throws {Error} If bot token is missing or invalid	
	 */
	getMyInfo: function (appId) {
		var info = Q.Users.appInfo("telegram", appId, true).appInfo;
		var id = Q.getObject("botId", info, null);
		if (!id) {
			var token = Q.getObject("token", info, null);
			if (token) {
				var parts = token.split(":");
				if (parts.length === 2 && !isNaN(parts[0])) {
					id = parseInt(parts[0], 10);
				}
			}
		}
		var is_bot = true;
		var username = Q.getObject("Users.apps.telegram." + appId + ".botUsername", Q.Config.get());
		return { id: id, is_bot: is_bot, username: username };
	},

	/**
	 * Get bot token from config
	 * @method tokenFromConfig
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @returns {string} The bot token
	 * @throws {Error} If bot token is missing or invalid
	 */
	tokenFromConfig: function (appId) {
		var info = Q.Users.appInfo("telegram", appId, true).appInfo;
		var token = Q.getObject("token", info);
		if (!token) {
			var fallback = Q.getObject("Users.apps.telegram.*.appIdForAuth", Q.Config.get());
			if (fallback) {
				var apps = Q.getObject("Users.apps.telegram", Q.Config.get(), {});
				for (var k in apps) {
					if (k !== "*") {
						var info2 = Q.Users.appInfo("telegram", k, true).appInfo;
						token = Q.getObject("token", info2);
						if (token) break;
					}
				}
			}
		}
		if (!token) {
			throw new Error("Missing Telegram bot token for app " + appId);
		}
		return token;
	},

	/**
	 * Get bot information using getMe API call
	 * @method getMe
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @returns {Promise} Resolves with the result of the getMe API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	getMe: function (appId) {
		return Telegram.Bot.api(appId, "getMe", {});
	},

	/**
	 * Construct Telegram API endpoint
	 * @method endpoint
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {string} methodName The Telegram API method name (e.g. "sendMessage")
	 * @returns {string} The full API endpoint URL
	 * @throws {Error} If bot token is missing or invalid
	 */
	endpoint: function (appId, methodName) {
		var token = Telegram.Bot.tokenFromConfig(appId);
		return "https://api.telegram.org/bot" + token + "/" + methodName;
	},

	/**
	 * Perform an API call
	 * @method api
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {string} methodName The Telegram API method name (e.g. "sendMessage")
	 * @param {Object} params The parameters to send in the API call
	 * @param {Object} [headers] Optional additional headers for the API call
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	api: function (appId, methodName, params, headers) {
		var endpoint = Telegram.Bot.endpoint(appId, methodName);
		var ua = Q.getObject("Telegram.bot.userAgent", Q.Config.get(), "Qbix");

		// Telegram API expects JSON body for everything except multipart
		var data = JSON.stringify(params);
		headers = headers || {
			"Accept": "application/json",
			"Content-Type": "application/json",
			"User-Agent": ua
		};

		return Q.promisify(function (cb) {
			Q.Utils.post(endpoint, data, null, ua, headers, function (err, body) {
				if (err) return cb(err);

				var result;
				try {
					result = JSON.parse(body);
				} catch (e) {
					return cb(e);
				}

				if (!result.ok) {
					return cb(new Error("Telegram API error: " + body));
				}

				cb(null, result);
			});
		});

	},

	/**
	 * Try to deduce chat_id from update object
	 * @method chatIdForReply
	 * @static
	 * @param {Object} params The parameters passed to the handler, containing the update object
	 * @returns {number|null} The chat ID to reply to, or null if not found
	 */
	chatIdForReply: function (params) {
		var u = params.update && params.update[params.updateType];
		return Q.getObject("from.id", u) ||
				Q.getObject("user.id", u) ||
				Q.getObject("chat.id", u, null);
	},

	/**
	 * Send text message
	 * @method sendMessage
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {number|string} chat_id The chat ID to send the message to
	 * @param {string} text The text of the message to send
	 * @param {Object} [options] Additional options for sendMessage API call (e.g. reply_markup)
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	sendMessage: function (appId, chat_id, text, options) {
		options = options || {};
		options.chat_id = chat_id;
		options.text = text;
		if (options.reply_markup && typeof options.reply_markup === "object") {
			options.reply_markup = JSON.stringify(options.reply_markup);
		}
		return Telegram.Bot.api(appId, "sendMessage", options);
	},

	/**
	 * Send a chat action (typing, upload_photo, etc.)
	 * @method sendChatAction
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {number|string} chat_id The chat ID to send the action to
	 * @param {string} action The type of action to send (e.g. "typing", "upload_photo")
	 * @param {Object} [options] Additional options for sendChatAction API call
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	sendChatAction: function (appId, chat_id, action, options) {
		options = options || {};
		options.chat_id = chat_id;
		options.action = action;
		return Telegram.Bot.api(appId, "sendChatAction", options);
	},

	/**
	 * Send photo
	 * @method sendPhoto
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {number|string} chat_id The chat ID to send the photo to
	 * @param {string|Buffer} photo The photo to send (file_id, URL, or file data)
	 * @param {Object} [options] Additional options for sendPhoto API call (e.g. caption)
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	sendPhoto: function (appId, chat_id, photo, options) {
		options = options || {};
		options.chat_id = chat_id;
		options.photo = photo;
		return Telegram.Bot.api(appId, "sendPhoto", options);
	},

	/**
	 * Answer inline query
	 * @method answerInlineQuery
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {string} inline_query_id The ID of the inline query to answer
	 * @param {Array|Object} results The results to return for the inline query (array of InlineQueryResult objects, or a single object)
	 * @param {Object} [options] Additional options for answerInlineQuery API call (e.g. cache_time, is_personal)
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	answerInlineQuery: function (appId, inline_query_id, results, options) {
		if (Array.isArray(results)) {
			results = JSON.stringify(results);
		}
		var params = Q.extend({}, options || {}, {
			inline_query_id: inline_query_id,
			results: results
		});
		return Telegram.Bot.api(appId, "answerInlineQuery", params);
	},

	/**
	 * Answer callback query (inline keyboard)
	 * @method answerCallbackQuery
	 * @static
	 * @param {string} appId The appId under Users/apps/telegram config
	 * @param {string} callback_query_id The ID of the callback query to answer
	 * @param {Object} [options] Additional options for answerCallbackQuery API call (e.g. text, show_alert)
	 * @returns {Promise} Resolves with the result of the API call, or rejects with an error
	 * @throws {Error} If bot token is missing or invalid
	 */
	answerCallbackQuery: function (appId, callback_query_id, options) {
		options = options || {};
		var params = Q.extend({ callback_query_id: callback_query_id }, options);
		return Telegram.Bot.api(appId, "answerCallbackQuery", params);
	}
};