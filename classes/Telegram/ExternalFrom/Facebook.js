"use strict";

/**
 * Class representing Facebook ExternalFrom rows.
 *
 * @module Users
 */
var Q = require('Q');
var Users = Q.require('Users');
var Users_ExternalFrom = Users.ExternalFrom;

/**
 * ExternalFrom adapter for Facebook
 *
 * @class Users.ExternalFrom.Facebook
 * @extends Users.ExternalFrom
 * @constructor
 */
function Users_ExternalFrom_Facebook(fields) {
	Users_ExternalFrom.constructors.apply(this, arguments);
}

module.exports =
	Users_ExternalFrom.Facebook =
	Users_ExternalFrom_Facebook;

/**
 * Get Facebook App access token (app_id|app_secret)
 *
 * @method appToken
 * @static
 */
Users_ExternalFrom_Facebook.appToken = function (appId) {
	var info = Users.appInfo('facebook', appId);
	if (!info || !info.appInfo) {
		throw new Error("Users.ExternalFrom.Facebook: Missing app config for " + appId);
	}

	var app = info.appInfo;
	if (!app.appId || !app.secret) {
		throw new Error("Users.ExternalFrom.Facebook: appId or secret missing in config");
	}

	return app.appId + "|" + app.secret;
};

/**
 * Facebook Graph API POST helper
 *
 * @method graphPost
 * @static
 */
Users_ExternalFrom_Facebook.graphPost = function (path, params) {
	var url = "https://graph.facebook.com" + path;

	return Q.promisify(function (cb) {
		Q.Utils.post(url, params, null, "Qbix", null, function (err, body) {
			if (err) return cb(err);

			var result;
			try {
				result = JSON.parse(body);
			} catch (e) {
				return cb(e);
			}

			if (result.error) {
				return cb(new Error("Facebook API error: " + JSON.stringify(result.error)));
			}

			cb(null, result);
		});
	});
};

/**
 * Sends a notification to the user via Facebook Game Notifications API.
 *
 * @method handlePushNotification
 * @param {Object} notification
 * @param {Function|null} callback
 */
Users_ExternalFrom_Facebook.prototype.handlePushNotification =
function (notification, callback) {

	var xid = this.fields.xid;
	if (!xid) {
		return Q.handle(callback, this, [
			new Q.Error("Users.ExternalFrom.Facebook: Missing xid")
		]);
	}

	// ----- Extract app config -----
	var appId = this.fields.appId;
	if (appId === 'all') {
		appId = Q.app.name;
	}

	var info = Users.appInfo('facebook', appId);
	if (!info || !info.appInfo) {
		return Q.handle(callback, this, [
			new Q.Error("Users.ExternalFrom.Facebook: Missing appInfo")
		]);
	}

	var app = info.appInfo;
	var accessToken;

	try {
		accessToken = Users_ExternalFrom_Facebook.appToken(appId);
	} catch (e) {
		return Q.handle(callback, this, [e]);
	}

	// ----- Build notification text -----
	var alert = notification.alert;
	var text = "";

	if (typeof alert === "string") {
		text = alert;
	} else if (alert && alert.body) {
		text = alert.body;
	}

	// Optional link
	var href = notification.href || "";
	if (href && href[0] === "/") {
		var baseUrl = Q.Config.get(['Users', 'apps', 'baseUrl'], "");
		href = baseUrl + href;
	}

	// ----- Perform Facebook API request -----
	var params = {
		template: text,
		href: href,
		access_token: accessToken
	};

	Users_ExternalFrom_Facebook.graphPost("/" + xid + "/notifications", params)
	.then(result => {
		Q.handle(callback, this, [null, result]);
	})
	.catch(err => {
		var msg = ("" + err.message).toLowerCase();

		// Permanent rejects
		if (
			msg.includes("forbidden") ||
			msg.includes("not authorized") ||
			msg.includes("invalid") ||
			msg.includes("app not allowed")
		) {
			err.rejected = true;
		}

		// Soft, retryable rejects
		if (msg.includes("rate") || msg.includes("429")) {
			err.rateLimited = true;
		}

		Q.handle(callback, this, [err]);
	});
};

Q.mixin(
	Users_ExternalFrom_Facebook,
	Users_ExternalFrom,
	Q.require('Base/Users/ExternalFrom')
);
