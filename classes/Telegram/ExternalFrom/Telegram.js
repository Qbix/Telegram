"use strict";

/**
 * Class representing Telegram ExternalFrom rows.
 *
 * @module Users
 */
var Q = require('Q');
var Users = Q.require('Users');
var Users_ExternalFrom = Users.ExternalFrom;

/**
 * ExternalFrom adapter for Telegram
 *
 * @class Users.ExternalFrom.Telegram
 * @extends Users.ExternalFrom
 * @constructor
 */
function Users_ExternalFrom_Telegram(fields) {
	Users_ExternalFrom.constructors.apply(this, arguments);
}
module.exports =
	Users_ExternalFrom.Telegram =
	Users_ExternalFrom_Telegram;

/**
 * Obtain Telegram bot API wrapper
 *
 * @method client
 * @static
 * @param {string} appId Internal app ID
 * @return {Object|null}
 */
Users_ExternalFrom_Telegram.client = function (appId) {
	try {
		var Telegram_Bot = require('Telegram/Bot');

		// Your Bot.js exports a single object, not a class,
		// so simply return it directly.
		return Telegram_Bot;
	} catch (e) {
		console.warn("[Users.ExternalFrom.Telegram] Could not load Telegram/Bot.js:", e);
		return null;
	}
};

/**
 * Sends a notification to the user over Telegram
 *
 * @method handlePushNotification
 */
Users_ExternalFrom_Telegram.prototype.handlePushNotification =
function (notification, callback) {

	var xid = this.fields.xid;
	if (!xid) {
		return Q.handle(callback, this, [
			new Q.Error("Users.ExternalFrom.Telegram: Missing xid")
		]);
	}

	// Retrieve telegram app info
	var info = Users.appInfo('telegram', this.fields.appId);
	if (!info || !info.appInfo || !info.appInfo.token) {
		return Q.handle(callback, this, [
			new Q.Error("Users.ExternalFrom.Telegram: Missing Telegram bot token")
		]);
	}

	var botAppId = info.appId;
	var baseUrl  = info.appInfo.baseUrl
		|| Q.Config.get(['Users','apps','baseUrl'], '');

	var text = "";
	var alert = notification.alert;

	if (typeof alert === 'string') {
		text = alert;
	} else if (alert && alert.body) {
		text = alert.body;
	}

	if (notification.href) {
		var link = notification.href;
		if (link[0] === '/') link = baseUrl + link;
		text += "\n\n" + link;
	}

	var bot = Users_ExternalFrom_Telegram.client(this.fields.appId);
	if (!bot) {
		return Q.handle(callback, this, [
			new Q.Error("Users.ExternalFrom.Telegram: Bot.js not available")
		]);
	}

	bot.sendMessage(botAppId, xid, text)
	.then(result => {
		Q.handle(callback, this, [null, result]);
	})
	.catch(err => {
		// ---- Telegram REJECTION LOGIC ----

		var msg = ("" + err.message).toLowerCase();

		// Hard permanent rejects
		if (
			msg.includes("forbidden") ||
			msg.includes("blocked") ||
			msg.includes("chat not found") ||
			msg.includes("deactivated") ||
			msg.includes("user is deactivated") ||
			msg.includes("peer_id_invalid")
		) {
			err.rejected = true;  // important flag upstream
		}

		// Soft rejects (retryable)
		if (msg.includes("too many requests") || msg.includes("429")) {
			err.rateLimited = true;
		}

		Q.handle(callback, this, [err]);
	});
};

Q.mixin(
	Users_ExternalFrom_Telegram,
	Users_ExternalFrom,
	Q.require('Base/Users/ExternalFrom')
);
