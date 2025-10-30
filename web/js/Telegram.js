/**
 * Telegram plugin's front end code
 *
 * @module Streams
 * @class Streams
 */
"use strict";
/* jshint -W014 */
(function(Q, $) {

Q.onInit.add(function _Telegram_autoDetect() {
	// may as well add this even in the browser context,
	// because the telegram links on desktop telegram open a regular browser
	Q.addScript('https://telegram.org/js/telegram-web-app.js?59', function () {
		try {
			window.Telegram.WebApp.ready();

			// Run only once
			if (Q.Users.loggedInUser) return;

			// Check if we're inside Telegram context
			var ctx = Q.Telegram.context();
			if (ctx === 'browser') return;

			if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) {
				var unsafe = Telegram.WebApp.initDataUnsafe;
				if (unsafe && unsafe.user && unsafe.user.id) {
					Q.Users.authPayload = Q.Users.authPayload || {};
					Q.Users.authPayload.telegram = {
						xid: unsafe.user.id,
						payload: Telegram.WebApp.initData,
						platform: 'telegram'
					};

					// Default future logins to auto-authenticate via Telegram
					Q.Users.login.options.autoAuthenticatePlatform = 'telegram';

					if (console && console.log)
						console.log('[Telegram] Auto-detected Telegram WebApp context, xid=' + unsafe.user.id);
				}
			}
		} catch (e) {
			if (console && console.warn)
				console.warn('[Telegram] auto-detect failed:', e);
		}
	});
}, 'Telegram');

var Telegram = Q.Telegram = {

	/**
	 * Detects current Telegram execution context.
	 * @method context
	 * @return {String} one of "app", "webview", "injected-ios", "injected-android", "injected-ios-modern", or "browser"
	 */
	context: function () {
		try {
			if (window.Telegram && window.Telegram.WebApp) return 'app';
			if (window.TelegramProxy) return 'webview';
			if (window.external && typeof window.external.notify === 'function') return 'injected-ios';
			if (window.Android && typeof window.Android.postMessage === 'function') return 'injected-android';
			if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.TelegramHandler)
				return 'injected-ios-modern';
		} catch (e) {}
		return 'browser';
	},

	/**
	 * @method isInjected
	 * @return {Boolean} true if running inside an injected iOS/Android WebView
	 */
	isInjected: function () {
		var ctx = Telegram.context();
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
		var ctx = Telegram.context();
		var payload = JSON.stringify({ eventType: name, eventData: data });

		try {
			switch (ctx) {
				case 'app':
					window.Telegram.WebApp.sendData(payload);
					break;
				case 'webview':
					if (window.TelegramProxy.postEvent)
						window.TelegramProxy.postEvent(name, data);
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
				window.Telegram.WebApp.close();
			} else if (ctx === 'webview' && window.TelegramProxy.close) {
				window.TelegramProxy.close();
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
				window.Telegram.WebApp.expand();
			} else if (ctx === 'webview' && window.TelegramProxy.expand) {
				window.TelegramProxy.expand();
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
			var ctx = Telegram.context();
			if (ctx === 'app' && window.Telegram.WebApp.openLink) {
				window.Telegram.WebApp.openLink(url, opts);
				return;
			}
			if (ctx === 'webview' && window.TelegramProxy.openLink) {
				window.TelegramProxy.openLink(url);
				return;
			}
		} catch (e) {}

		try {
			location.href = url;
		} catch (e) {
			if (console && console.warn) console.warn('openLink fallback failed', e);
		}
	}
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
		var ctx = Telegram.context();
		if (ctx === 'webview' || Telegram.isInjected()) {
			e.preventDefault();
			Telegram.openLink(href);
		}
	} catch (ex) {
		if (console && console.warn) console.warn('Link intercept failed', ex);
	}
}, true);

Q.Users.authenticate.telegram = new Q.Method({}, {
	customPath: '{{Telegram}}/js/methods/Users/authenticate/telegram.js'
});

Q.text.Users.login.telegram = {
	src: null,
	alt: "log in with telegram"
};

})(Q, Q.jQuery);