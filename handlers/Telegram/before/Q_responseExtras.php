<?php

function Telegram_before_Q_responseExtras() {
	Q_Response::addScript('{{Telegram}}/js/Telegram.js');
}
