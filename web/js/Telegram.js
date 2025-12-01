/**
 * Telegram plugin's front end code
 *
 * @module Telegram
 * @class Telegram
 */
"use strict";
/* jshint -W014 */
(function(Q, $) {
//
Q.onInit.add(function _Telegram_autoDetect() {
	// may as well add this even in the browser context,
	// because the telegram links on desktop telegram open a regular browser
	Q.addScript('https://telegram.org/js/telegram-web-app.js?59', function () {
		var Telegram = window.Telegram;
		try {
			Telegram.WebApp.ready();

			if (!Telegram || !Telegram.WebApp || !Telegram.WebApp.initData) {
				// we are not in a mini-app, may as well provision an intent now
				Q.Users.Intent.provision('Users/authenticate', 'telegram', Q.info.app);
				Q.Users.onLogout.set(function () {
					Q.Users.Intent.provision('Users/authenticate', 'telegram', Q.info.app);
				}, 'Telegram');
			}

			// Check if we're inside Telegram context
			var ctx = Q.Telegram.context();
			if (ctx === 'browser') return;

			// we are in a mini-app
			var unsafe = Q.getObject('Telegram.WebApp.initDataUnsafe');
			if (unsafe && unsafe.user && unsafe.user.id) {
				Q.Users.authPayload = Q.Users.authPayload || {};
				Q.Users.authPayload.telegram = {
					xid: unsafe.user.id,
					payload: Telegram.WebApp.initData,
					platform: 'telegram'
				};

				// Default future logins to auto-authenticate via Telegram
				Q.Users.login.options.autoAuthenticatePlatform = 'telegram';

				if (console && console.log) {
					console.log('[Telegram] Auto-detected Telegram WebApp context, xid=' + unsafe.user.id);
				}

				Q.handle(Q.Telegram.onWebAppContext, Telegram, [unsafe]);
			}
		} catch (e) {
			if (console && console.warn)
				console.warn('[Telegram] auto-detect failed:', e);
		}
	});
}, 'Telegram');

var T = Q.Telegram = Q.plugins.Telegram = {

	/**
	 * Detects current Telegram execution context.
	 * @method context
	 * @return {String} one of "app", "webview", "injected-ios", "injected-android", "injected-ios-modern", or "browser"
	 */
	context: function () {
		try {
			if (Q.getObject('Telegram.WebApp.initData')) {
				return 'app';
			}
			if (window.Telegram && window.TelegramWebviewProxy) {
				return 'webview';
			}
		} catch (e) {}
		return 'browser';
	},

	/**
	 * @method isInjected
	 * @return {Boolean} true if running inside an injected iOS/Android WebView
	 */
	isInjected: function () {
		var ctx = T.context();
		return ctx.indexOf('injected') === 0;
	},

	/**
	 * Sends a message to Telegram bridge, depending on context.
	 * @method postMessage
	 * @param {String} name
	 * @param {Object} [data]
	 */
	postMessage: function (name, data) {
		if (!data) data = {};
		var ctx = T.context();
		var payload = JSON.stringify({ eventType: name, eventData: data });

		try {
			switch (ctx) {
				case 'app':
					Telegram.WebApp.sendData(payload);
					break;
				case 'webview':
					if (TelegramProxy.postEvent)
						TelegramProxy.postEvent(name, data);
					break;
				case 'injected-android':
					window.Android.postMessage(payload);
					break;
				case 'injected-ios':
					window.external.notify(payload);
					break;
				case 'injected-ios-modern':
					window.webkit.messageHandlers.TelegramHandler.postMessage(payload);
					break;
				default:
					if (console && console.log)
						console.log('[Telegram.postMessage]', name, data);
			}
		} catch (e) {
			if (console && console.warn)
				console.warn('Telegram.postMessage failed:', e);
		}
	},

	/**
	 * Requests Telegram to close the current WebApp window.
	 * @method close
	 */
	close: function () {
		try {
			var ctx = Telegram.context();
			if (ctx === 'app') {
				Telegram.WebApp.close();
			} else if (ctx === 'webview' && TelegramProxy.close) {
				TelegramProxy.close();
			} else {
				Telegram.postMessage('close');
			}
		} catch (e) {
			if (console && console.warn) console.warn('Telegram.close failed', e);
		}
	},

	/**
	 * Expands the WebApp to full height inside Telegram.
	 * @method expand
	 */
	expand: function () {
		try {
			var ctx = Telegram.context();
			if (ctx === 'app') {
				Telegram.WebApp.expand();
			} else if (ctx === 'webview' && TelegramProxy.expand) {
				TelegramProxy.expand();
			}
		} catch (e) {
			if (console && console.warn) console.warn('Telegram.expand failed', e);
		}
	},

	/**
	 * Opens a link inside Telegram WebView, or falls back to location.href
	 * @method openLink
	 * @param {String} url
	 * @param {Object} [opts]
	 */
	openLink: function (url, opts) {
		if (!opts) opts = {};
		try {
			var ctx = T.context();
			if (ctx === 'app' && Telegram.WebApp.openLink) {
				Telegram.WebApp.openLink(url, opts);
				return;
			}
			if (ctx === 'webview' && TelegramProxy.openLink) {
				TelegramProxy.openLink(url);
				return;
			}
		} catch (e) {}

		try {
			location.href = url;
		} catch (e) {
			if (console && console.warn) console.warn('openLink fallback failed', e);
		}
	},

	onWebAppContext: new Q.Event()
}

// Auto-intercept links in Telegram WebView
document.addEventListener('click', function (e) {
	try {
		var t = e.target;
		while (t && t.tagName !== 'A') t = t.parentNode;
		if (!t) return;
		var href = t.getAttribute('href');
		if (!href || href.indexOf('#') === 0 || href.indexOf('javascript:') === 0)
			return;
		var ctx = T.context();
		if (ctx === 'webview' || T.isInjected()) {
			e.preventDefault();
			T.openLink(href);
		}
	} catch (ex) {
		if (console && console.warn) console.warn('Link intercept failed', ex);
	}
}, true);

T.onWebAppContext.add(function (data) {
	var info = Q.getObject(['telegram', Q.info.app], Q.Users.apps);
	if (!Q.Users.login.options.autoAuthenticatePlatform
	|| info.dontLoginAutomatically) {
		return;
	}
	if (Q.Users.loggedInUser) {
		var startParam = Q.getObject('Telegram.WebApp.initDataUnsafe.start_param');
		if (!startParam || startParam.split('-')[0] !== 'intent') {
			// user is already logged in, and no Users/authenticate intent
			return;
		}
	}
	// log in the user, and also save results in any session that generated the intent
	Q.Users.login({
		using: 'telegram',
		tryQuietly: true,
		prompt: false
	});
}, 'Telegram');

Q.Users.beforeDefineAuthenticateMethods.add(function (authenticate) {
	authenticate.telegram = new Q.Method({}, {
		customPath: '{{Telegram}}/js/methods/Users/authenticate/telegram.js'
	});
}, 'Telegram');

Q.text.Users.login.telegram = {
	src: null,
	alt: "log in with telegram"
};

Q.Streams.Stream.subscribe.options.permissions = {
	telegram: true
};

Q.Streams.Stream.subscribe.onPermission.set(function(info) {
	if (!info.options.telegram) {
		return; // do nothing — allow others to handle
	}
	var allows = Q.getObject('Telegram.WebApp.initDataUnsafe.allows_write_to_pm');
	if (allows) {
		return false; // no need for anything else
	}
	var rwa = Q.getObject('window.Telegram.WebApp.requestWriteAccess');
	if (rwa) {
		rwa(function (result) { 
			console.log("Write access:", result);
		});
		return false;
	}
}, 'Telegram');


})(Q, Q.jQuery);