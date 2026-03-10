Q.exports(function (Users, priv) {

	/**
	 * Authenticates this session with a given platform,
	 * if the user was already connected to it.
	 * It tries to do so by checking a cookie that would have been set by the server,
	 * or Telegram Mini App context if available.
	 *
	 * @method authenticate
	 * @param {String} platform Currently it's `telegram`
	 * @param {String} appId can be the appId, or "all"
	 * @param {Function} onSuccess Called if the user successfully authenticates with the platform, or was already authenticated.
	 *  It is passed the user information if the user changed.
	 * @param {Function} onCancel Called if the authentication was canceled. Receives err, options
	 * @param {Object} [options] object of parameters for authentication function
	 *   @param {Function|Boolean} [options.prompt=null] which shows the usual prompt unless it was already rejected once.
	 *     Can be false, in which case the user is never prompted and the authentication just happens.
	 *     Can be true, in which case the usual prompt is shown even if it was rejected before.
	 *     Can be a function with an onSuccess and onCancel callback, in which case it's used as a prompt.
	 *   @param {Boolean} [options.force] forces a status refresh
	 *   @param {String} [options.appId=Q.info.app] Only needed if you have multiple apps on platform
	 *   @param {Boolean} [options.startapp] set to true to use the Mini App flow (`startapp`)
	 *   @param {String} [options.startappName] optional Telegram Mini App short name (for future use)
	 */
	function telegram(platform, appId, onSuccess, onCancel, options) {

		options = Q.extend({}, options);

		if (options.startapp === undefined) {
			options.startapp = Q.getObject([platform, options.appId, 'startapp'], Users.apps);
		}

		var initData = null;
		var unsafe = null;
		var user = null;
		var xid = null;

		try {
			if (window.Telegram && window.Telegram.WebApp) {
				unsafe = Telegram.WebApp.initDataUnsafe;
				user = unsafe.user;
				initData = Telegram.WebApp.initData || null;
				if (user && user.id) {
					xid = user.id;
				}
			}
		} catch (e) {
			if (console && console.warn) {
				console.warn('Telegram context initialization error:', e);
			}
		}

		var optimisticPayload = user ? {
			optimisticId: Q.Optimistic.id(),
			icon: user.photo_url || null,
			username: user.username || null,
			firstName: user.first_name || null,
			lastName: user.last_name || null,
			gender: null
		} : null;

		if (optimisticPayload) {
			Q.handle(Q.Optimistic.onBegin("avatar", "@me"), Q.Users, [optimisticPayload]);
		}

		var cookieName = 'tgsr_' + appId;
		var hasCookie = !!Q.cookie(cookieName);
		var hasInitData = !!initData;

		// ============================================================
		// CASE 1 + 2: Cookie or initData available
		// ============================================================

		if (hasCookie || hasInitData) {

			appId = options && options.appId || appId;

			var fields = {
				platform: 'telegram',
				appId: appId
			};

			if (hasInitData) {
				fields['Q.Users.authPayload.telegram'] = initData;
			}

			Q.req(
				'Users/authenticate',
				function (err, response) {

					if (err) {

						if (console && console.warn) {
							console.warn('Telegram authenticate failed:', err);
						}

						if (typeof onCancel === 'function') {
							onCancel(err, options);
						}

						Q.cookie(cookieName, null, { path: '/' });
						Q.cookie(cookieName + '_expires', null, { path: '/' });

						Q.handle(Q.Optimistic.onReject("avatar", "@me"), Q.Users, {});
						return;
					}

					var userId =
						(response && response.user && response.user.id) ||
						xid ||
						null;

					priv.handleXid(
						platform,
						appId,
						userId,
						function () {
							if (optimisticPayload) {
								Q.handle(Q.Optimistic.onResolve("avatar", "@me"), Q.Users, optimisticPayload);
							}
							Q.handle(onSuccess, this, arguments);
						},
						function () {
							if (optimisticPayload) {
								Q.handle(Q.Optimistic.onReject("avatar", "@me"), Q.Users, optimisticPayload);
							}
							Q.handle(onCancel, this, arguments);
						},
						Q.extend({ response: response }, options)
					);
				},
				{
					method: 'POST',
					fields: fields
				}
			);

			return;
		}

		// ============================================================
		// CASE 3: Redirect to Telegram Intent
		// ============================================================

		var capability = Q.getObject(
			['Users/authenticate', 'telegram', Q.info.app, 'capability'],
			Q.Users.Intent.provision.results
		);

		if (!capability) {
			console.warn("Users.authenticate: Telegram missing capability for Users/authenticate action in " + Q.info.app);
			if (typeof onCancel === 'function') {
				onCancel(err, options);
			}
			return false;
		}

		var canHaveMiniApps = Q.info.isMobile || Q.info.isTablet;

		Q.Users.Intent.start(
			capability,
			{
				action: 'Users/authenticate',
				platform: 'telegram',
				interpolate: {
					parameter: options.startapp && canHaveMiniApps ? 'startapp' : 'start'
				},
				interpolateQR: {
					parameter: options.startapp ? 'startapp' : 'start'
				}
			}
		);
	}

	// ============================================================
	// Prompt Adapter Registration
	// ============================================================

	Users.prompt = Users.prompt || {};

	Users.prompt.telegram = {

		template: null,

		getData: function (context) {
			return context;
		},

		render: function (context, container, done) {

			var currentXid = Q.getObject(['loggedInUser', 'xids', 'telegram'], Users);
			var newXid = context.xid;

			var unsafe = null;
			var user = null;

			try {
				if (window.Telegram && window.Telegram.WebApp) {
					unsafe = Telegram.WebApp.initDataUnsafe;
					user = unsafe.user;
				}
			} catch (e) {}

			var icon = user && user.photo_url
				? user.photo_url
				: Q.url('{{Users}}/img/platforms/telegram.png');

			var caption;

			if (currentXid && currentXid !== newXid) {
				caption = Q.text.Users.prompt.doSwitch.interpolate({
					platform: 'telegram',
					Platform: 'Telegram'
				});
			} else {
				caption = Q.text.Users.prompt.doAuth.interpolate({
					platform: 'telegram',
					Platform: 'Telegram'
				});
			}

			if (currentXid && currentXid !== newXid) {
				container.append(_userBlock(
					currentXid,
					icon,
					Q.text.Users.prompt.noLongerUsing.interpolate({
						platform: 'telegram',
						Platform: 'Telegram'
					})
				));
			}

			container
				.append(_userBlock(
					newXid,
					icon,
					Q.text.Users.prompt.areUsing.interpolate({
						platform: 'telegram',
						Platform: 'Telegram'
					})
				))
				.append(_authenticateActions(caption));

			done && done();
		}
	};

	function _userBlock(xid, icon, explanation) {

		return $("<div class='Users_telegram_block' />").append(
			$("<div class='Users_telegram_row' />")
				.append($("<img class='Users_telegram_icon' />").attr('src', icon))
				.append($("<div class='Users_telegram_text' />")
					.append($("<div class='Users_explanation' />").html(explanation))
					.append($("<div class='Users_telegram_id' />").text(xid))
				)
		);
	}

	function _authenticateActions(caption) {
		return $("<div class='Users_actions Q_big_prompt' />").append(
			$('<button type="submit" class="Q_button Q_main_button Users_confirm" />')
				.html(caption)
		);
	}

	return telegram;
});