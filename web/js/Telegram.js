/**
 * Telegram plugin's front end code
 *
 * @module Streams
 * @class Streams
 */
"use strict";
/* jshint -W014 */
(function(Q, $) {

/**
* Authenticates this session with a given platform,
* if the user was already connected to it.
* It tries to do so by checking a cookie that would have been set by the server.
* @method authenticate
* @param {String} platform Currently it's `telegram`
* @param {String} platformAppId platformAppId
* @param {Function} onSuccess Called if the user successfully authenticates with the platform, or was already authenticated.
*  It is passed the user information if the user changed.
* @param {Function} onCancel Called if the authentication was canceled. Receives err, options
* @param {Object} [options] object of parameters for authentication function
*   @param {Function|Boolean} [options.prompt=null] which shows the usual prompt unless it was already rejected once.
*     Can be false, in which case the user is never prompted and the authentication just happens.
*     Can be true, in which case the usual prompt is shown even if it was rejected before.
*     Can be a function with an onSuccess and onCancel callback, in which case it's used as a prompt.
*   @param {Boolean} [options.force] forces the getLoginStatus to refresh its status
*   @param {String} [options.appId=Q.info.app] Only needed if you have multiple apps on platform
*/
Q.Users.authenticate.telegram = function telegram(platform, platformAppId, onSuccess, onCancel, options) {
   options = options || {};
   
   if (response.status === 'connected') {
       priv.handleXid(
           platform, platformAppId, response.authResponse.userID,
           onSuccess, onCancel, Q.extend({response: response}, options)
       );
   } else if (platformAppId) {
       // let's delete any stale facebook cookies there might be
       // otherwise they might confuse our server-side authentication.
       Q.cookie('fbs_' + platformAppId, null, {path: '/'});
       Q.cookie('fbsr_' + platformAppId, null, {path: '/'});
       priv._doCancel(platform, platformAppId, null, onSuccess, onCancel, options);
   }
};
    
})(Q, Q.jQuery);
