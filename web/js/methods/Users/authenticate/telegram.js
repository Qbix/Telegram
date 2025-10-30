Q.exports(function (Users, priv) {
    /**
    * Authenticates this session with a given platform,
    * if the user was already connected to it.
    * @method authenticate
    * @param {String} platform Currently it's `telegram`
    * @param {String} platformAppId platformAppId
    * @param {Function} onSuccess Called if the user successfully authenticates with the platform, or was already authenticated.
    * @param {Function} onCancel Called if the authentication was canceled.
    * @param {Object} [options] authentication options
    *   @param {Boolean} [options.startapp=false] set to true to use the Mini App flow (`startapp`)
    *   @param {String} [options.startappName] optional Telegram Mini App short name (for future use)
    *   @param {Function|Boolean} [options.prompt=null] see docs
    *   @param {Boolean} [options.force] forces a status refresh
    *   @param {String} [options.appId=Q.info.app] Only needed if you have multiple apps on platform
    */
    function telegram(platform, platformAppId, onSuccess, onCancel, options) {
        options = options || {};

        var cookieName = 'tgsr_' + platformAppId;
        var cookie = Q.cookie(cookieName);
        var cookieUserId = null;
        if (cookie) {
            try {
                var parsed = JSON.parse(cookie);
                if (parsed && parsed.user && parsed.user.id) {
                    cookieUserId = parsed.user.id;
                }
            } catch (e) {}
        }

        var ctxUserId = null, initData = null, unsafe = null;
        try {
            if (window.Telegram && window.Telegram.WebApp) {
                unsafe = Telegram.WebApp.initDataUnsafe;
                if (unsafe && unsafe.user && unsafe.user.id) {
                    ctxUserId = unsafe.user.id;
                }
                initData = Telegram.WebApp.initData;
            }
        } catch (e) {}

        // CASE 1: Already have cookie and it matches Telegram context user
        if (cookieUserId && (!ctxUserId || ctxUserId == cookieUserId)) {
            return priv.handleXid(
                platform, platformAppId, cookieUserId,
                onSuccess, onCancel, options
            );
        }

        // CASE 2: Have Telegram context with initData → verify via backend
        if (initData && ctxUserId) {
            Q.Users.authPayload = Q.Users.authPayload || {};
            Q.Users.authPayload.telegram = {
                xid: ctxUserId,
                payload: initData,
                platform: 'telegram'
            };

            Q.req('Users/authenticate', function (err, response) {
                if (err) {
                    if (console && console.warn) console.warn('Telegram authenticate failed:', err);
                    if (typeof onCancel === 'function') onCancel(err, options);
                    return;
                }
                if (typeof onSuccess === 'function') onSuccess(response);
            }, {
                method: 'POST',
                fields: {
                    platform: 'telegram',
                    initData: initData
                }
            });
            return;
        }

        // CASE 3: Neither cookie nor context → fallback to intent redirect
        var parameter = options.startapp ? 'startapp' : 'start';
        var interpolate = { parameter: parameter };
        if (options.startappName) interpolate.shortName = options.startappName;

        Q.handle(Q.action('Users/intent', {
            action: 'Users/authenticate',
            platform: 'telegram',
            interpolate: interpolate
        }));

        Q.onVisibilityChange.setOnce(function (isShown) {
            if (!isShown) return;
            Q.loadUrl(location.href, {
                slotNames: Q.info.slotNames,
                loadExtras: 'all',
                ignoreDialogs: true,
                ignorePage: false,
                ignoreHistory: true,
                quiet: true
            });
        }, 'Telegram');
    };
    telegram.options = {};
    return telegram;
});