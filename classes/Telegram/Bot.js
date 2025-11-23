"use strict";
(function (Q, $) {

	var Telegram = Q.plugins.Telegram = Q.plugins.Telegram || {};

	/**
	 * Telegram Bot API wrapper (client-side or server-side)
	 * Mirrors PHP Telegram class (sendMessage, sendPhoto, etc.)
	 * @class Telegram.Bot
	 * @namespace Q.plugins.Telegram
	 */
	Telegram.Bot = {

		/**
		 * Get bot token from config
		 * @method tokenFromConfig
		 * @static
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
		 * Construct Telegram API endpoint
		 * @method endpoint
		 * @static
		 */
		endpoint: function (appId, methodName) {
			var token = Telegram.Bot.tokenFromConfig(appId);
			return "https://api.telegram.org/bot" + token + "/" + methodName;
		},

		/**
		 * Perform an API call
		 * @method api
		 * @static
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
		 */
		answerCallbackQuery: function (appId, callback_query_id, options) {
			options = options || {};
			var params = Q.extend({ callback_query_id: callback_query_id }, options);
			return Telegram.Bot.api(appId, "answerCallbackQuery", params);
		}
	};

})(Q, jQuery);
