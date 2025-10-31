/**
 * Telegram ExternalTo adapter
 *
 * @module Users
 */
const Q = require('Q');
const Users = Q.require('Users');
const Users_ExternalTo = Users.ExternalTo;

/**
 * @class Users.ExternalTo.Telegram
 * @extends Users.ExternalTo
 */
function Users_ExternalTo_Telegram(fields) {
	Users_ExternalTo.constructors.apply(this, arguments);
}
module.exports = Users_ExternalTo.Telegram = Users_ExternalTo_Telegram;

/**
 * Send Telegram notification via Q.plugins.Telegram.Bot
 * Mirrors the pushNotification() interface of other ExternalTo adapters.
 *
 * @param {Object} notification
 *   @param {String} notification.alert  Main text body
 *   @param {String} [notification.href] Optional URL (creates inline button)
 *   @param {String} [notification.parse_mode="HTML"] Parse mode (HTML, MarkdownV2, etc.)
 * @param {Function} callback
 */
Users_ExternalTo_Telegram.prototype.handlePushNotification = function (notification, callback) {
	if (!this.fields.xid) {
		return Q.handle(callback, this, [new Q.Error('Users.ExternalTo.Telegram: missing xid (chat_id)')]);
	}

	try {
		const TelegramBot = Q.plugins.Telegram.Bot;
		const appId = this.appId || Q.app();
		const chat_id = this.fields.xid;
		const text = notification.alert || '';
		const opts = {
			parse_mode: notification.parse_mode || 'HTML',
			disable_web_page_preview: true
		};

		if (notification.href) {
			opts.reply_markup = {
				inline_keyboard: [[{ text: 'Open', url: notification.href }]]
			};
		}

		TelegramBot.sendMessage(appId, chat_id, text, opts)
			.then(result => Q.handle(callback, this, [null, result]))
			.catch(err => Q.handle(callback, this, [err]));
	} catch (e) {
		Q.handle(callback, this, [e]);
	}
};

// mix in base ExternalTo behavior
Q.mixin(
	Users_ExternalTo_Telegram,
	Users_ExternalTo,
	Q.require('Base/Users/ExternalTo')
);

/**
 * The setUp() method is called the first time
 * an object of this class is constructed.
 * @method setUp
 */
Users_ExternalTo.prototype.setUp = function () {
	// put any code here
	// overrides the Base class
};