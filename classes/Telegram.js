/**
 * Telegram model
 * @module Telegram
 * @main Telegram
 */
var Q = require('Q');

/**
 * Static methods for the Telegram model
 * @class Telegram
 * @extends Base.Telegram
 * @static
 */
function Telegram() { };
module.exports = Telegram;

var Base_Telegram = Q.require('Base/Telegram');
Q.mixin(Telegram, Base_Telegram);

/*
 * This is where you would place all the static methods for the models,
 * the ones that don't strongly pertain to a particular row or table.
 * Just assign them as methods of the Telegram object.
 * If file 'Telegram.js.inc' exists, its content is included
 * * * */

/* * * */