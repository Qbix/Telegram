(function (Q, $, window, undefined) {

/**
 * Extends Streams/chat with Telegram-compatible text parsing (MarkdownV2,
 * legacy Markdown, HTML), inline keyboards, and auto-rendered audio/video
 * players for URLs with known media extensions.
 *
 * Parse mode resolution:
 *   1. message.instructions["Telegram/format"].parse_mode (explicit)
 *   2. Auto-detect: content contains an allowed HTML tag -> "HTML",
 *      otherwise "MarkdownV2"
 *
 * Inline keyboards come from message.instructions["Telegram/format"].reply_markup
 * in Telegram Bot API shape: { inline_keyboard: [[{text, url|callback_data|...}]] }.
 * Composer text is NEVER scanned for keyboard syntax.
 *
 * Callback button taps post Streams messages of configurable types (default
 * "Telegram/chat/callback") to the same stream, with the button payload in
 * instructions. Register these types in plugin.json under
 *   Streams/types/<streamType>/messages/Telegram/chat/callback/post
 * so the stream will accept them.
 *
 * @class Telegram/format/chat
 * @constructor
 * @param {Object} [options]
 *   @param {String} [options.defaultParseMode="MarkdownV2"]
 *     Used only when auto-detect doesn't fire and instructions don't specify.
 *   @param {Array}  [options.mediaExtensions.audio]
 *     Lowercase extensions rendered via Streams/audio/preview.
 *   @param {Array}  [options.mediaExtensions.video]
 *     Lowercase extensions rendered via Streams/video/preview.
 *   @param {Boolean} [options.scanBareUrls=true]
 *     Fallback: scan plain text for bare media URLs after entity parsing.
 *   @param {String} [options.callbackMessageType="Telegram/chat/callback"]
 *     Message type posted when a callback_data button is tapped.
 *   @param {Q.Event} [options.onButtonTap]
 *     Fires for any button tap; receives ({type, payload, button}, $messageEl).
 *   @param {Q.Event} [options.onCallback]
 *     Fires specifically for callback_data taps, after the message is posted.
 */
Q.Tool.define("Telegram/format/chat", ["Streams/chat"], function (options) {
	var tool = this;
	var state = tool.state;

	tool.chatTool = Q.Tool.from(this.element, "Streams/chat");
	if (!tool.chatTool) {
		throw new Q.Error("Telegram/format/chat: must be on a Streams/chat element");
	}

	// Render hook for brand-new messages (both sent and received)
	tool.chatTool.state.onMessageRender.set(function (fields, html) {
		var $html = $(fields.html || html);
		tool.applyFormat($html, fields);
		fields.html = $html[0].outerHTML;
	}, tool);

	// Process messages that were already in the DOM when we activated
	Q.each($(".Streams_chat_item", tool.chatTool.element), function () {
		tool.applyFormat(this, {
			instructions: this.getAttribute("data-instructions"),
			ordinal: this.getAttribute("data-ordinal"),
			byUserId: this.getAttribute("data-byuserid")
		});
	});

	// Delegate button clicks
	$(tool.chatTool.element).on(
		Q.Pointer.fastclick,
		".Telegram_format_button",
		function (e) {
			e.preventDefault();
			e.stopPropagation();
			tool.handleButtonTap($(this));
			return false;
		}
	);

	// Spoiler tap-to-reveal
	$(tool.chatTool.element).on(
		Q.Pointer.fastclick,
		".Streams_chat_message_content tg-spoiler",
		function (e) {
			e.stopPropagation();
			$(this).toggleClass("Telegram_format_spoiler_revealed");
		}
	);
},

{
	defaultParseMode: "MarkdownV2",
	scanBareUrls: true,
	callbackMessageType: "Telegram/chat/callback",
	botUsername: "bot",
	mediaExtensions: {
		audio: ["mp3", "m4a", "ogg", "oga", "wav", "flac", "aac", "opus"],
		video: ["mp4", "m4v", "webm", "mov", "ogv"]
	},
	onButtonTap: new Q.Event(),
	onCallback: new Q.Event()
},

{
	/**
	 * Main entry: mutate a rendered chat-item element to apply formatting,
	 * media embeds, and inline keyboard.
	 * @method applyFormat
	 */
	applyFormat: function ($el, fields) {
		var tool = this;
		if (!($el instanceof $)) $el = $($el);
		if ($el.attr("data-telegramFormatted")) return $el;

		var instructions = tool.parseInstructions(fields.instructions);
		var fmt = instructions["Telegram/format"] || {};

		var $content = $(".Streams_chat_message_content", $el);
		if (!$content.length) return $el;

		// Source of truth is fields.content (the message.content column).
		// On the initial-DOM-scan path we may not have it, so fall back to
		// the rendered text — which is what Streams/chat put there from
		// message.content in the first place.
		var rawText = fields.content != null ? fields.content : $content.text();
		var parseMode = fmt.parse_mode || tool.detectParseMode(rawText);
		var parsed = tool.parseContent(rawText, parseMode);

		// 2. Media extraction: parsed entities first, then bare URLs as fallback
		var mediaUrls = tool.extractMediaUrls(parsed.urls, rawText);

		// 3. Swap content HTML (sanitized)
		if (parsed.html != null) {
			$content.html(parsed.html, true);
		}

		// 4. Append media players
		Q.each(mediaUrls, function (i, m) {
			tool.appendMediaTool($el, m);
		});

		// 5. Append inline keyboard
		if (fmt.reply_markup && fmt.reply_markup.inline_keyboard) {
			tool.appendKeyboard($el, fmt.reply_markup.inline_keyboard, {
				ordinal: fields.ordinal,
				byUserId: fields.byUserId
			});
		}

		$el.attr("data-telegramFormatted", 1);
		return $el;
	},

	parseInstructions: function (raw) {
		if (!raw) return {};
		if (typeof raw === "object") return raw;
		try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
	},

	/**
	 * Heuristic: if any allowed Telegram HTML tag appears (<b>, <i>, <a>, etc.)
	 * treat as HTML, else fall back to configured default.
	 * @method detectParseMode
	 */
	detectParseMode: function (text) {
		var tags = "b|strong|i|em|u|ins|s|strike|del|tg-spoiler|a|code|pre|blockquote";
		var re = new RegExp("<(" + tags + ")(\\s[^>]*)?>", "i");
		return re.test(text) ? "HTML" : this.state.defaultParseMode;
	},

	/**
	 * Dispatcher to parse-mode-specific implementations.
	 * @method parseContent
	 * @return {Object} {html, entities, urls}
	 */
	parseContent: function (text, mode) {
		switch (mode) {
			case "HTML":       return this.parseHTML(text);
			case "Markdown":   return this.parseMarkdownLegacy(text);
			case "MarkdownV2":
			default:           return this.parseMarkdownV2(text);
		}
	},

	// -----------------------------------------------------------------
	// HTML mode
	// Allowed per Telegram spec: b, strong, i, em, u, ins, s, strike,
	// del, tg-spoiler, a[href], code, pre, blockquote.
	// Everything else is escaped.
	// -----------------------------------------------------------------
	parseHTML: function (text) {
		var ALLOWED = {
			b: {}, strong: {}, i: {}, em: {}, u: {}, ins: {},
			s: {}, strike: {}, del: {},
			"tg-spoiler": {},
			a: { href: true },
			code: { "class": true }, // for language-xxx
			pre: {},
			blockquote: {}
		};
		var urls = [];
		// Tokenize: split into tags and text. We'll build a whitelisted output.
		var out = [];
		var i = 0, len = text.length;
		while (i < len) {
			var lt = text.indexOf("<", i);
			if (lt < 0) { out.push(escapeText(text.slice(i))); break; }
			if (lt > i) out.push(escapeText(text.slice(i, lt)));
			var gt = text.indexOf(">", lt);
			if (gt < 0) { out.push(escapeText(text.slice(lt))); break; }
			var raw = text.slice(lt + 1, gt);
			var closing = raw.charAt(0) === "/";
			var body = closing ? raw.slice(1) : raw;
			var spaceIdx = body.search(/\s/);
			var name = (spaceIdx < 0 ? body : body.slice(0, spaceIdx)).toLowerCase();
			var attrsStr = spaceIdx < 0 ? "" : body.slice(spaceIdx);
			if (!ALLOWED.hasOwnProperty(name)) {
				out.push(escapeText(text.slice(lt, gt + 1))); // escape the tag
			} else if (closing) {
				out.push("</" + name + ">");
			} else {
				var allowedAttrs = ALLOWED[name];
				var attrs = parseAttrs(attrsStr);
				var safeAttrs = "";
				for (var k in attrs) {
					if (!allowedAttrs[k]) continue;
					var v = attrs[k];
					if (k === "href" && !isSafeUrl(v)) continue;
					if (k === "href") urls.push(v);
					safeAttrs += " " + k + '="' + escapeAttr(v) + '"';
				}
				out.push("<" + name + safeAttrs + ">");
			}
			i = gt + 1;
		}
		return { html: out.join(""), urls: urls, entities: null };

		function escapeText(s) {
			return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
		}
		function escapeAttr(s) {
			return String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
		}
		function parseAttrs(s) {
			var attrs = {};
			var re = /(\w[\w-]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/g;
			var m;
			while ((m = re.exec(s))) attrs[m[1].toLowerCase()] = m[2] || m[3] || m[4] || "";
			return attrs;
		}
	},

	// -----------------------------------------------------------------
	// MarkdownV2 mode
	// Spec: *bold* _italic_ __underline__ ~strike~ ||spoiler||
	//       `code` ```pre``` [text](url) >blockquote
	// Escape char: \ before any of _*[]()~`>#+-=|{}.!
	// -----------------------------------------------------------------
	parseMarkdownV2: function (text) {
		return this._parseMarkdownCommon(text, {
			escapeChars: "_*[]()~`>#+-=|{}.!",
			spoiler: true,
			strike: true,
			blockquote: true,
			underline: true
		});
	},

	// Legacy Markdown: *bold* _italic_ `code` ```pre``` [text](url).
	// No escaping, no spoiler, no strikethrough.
	parseMarkdownLegacy: function (text) {
		return this._parseMarkdownCommon(text, {
			escapeChars: "",
			spoiler: false,
			strike: false,
			blockquote: false,
			underline: false
		});
	},

	_parseMarkdownCommon: function (text, opts) {
		var urls = [];
		// Handle backslash escapes by replacing them with placeholder tokens
		// we'll swap back in as literal chars at the end.
		var placeholders = [];
		function stash(ch) {
			placeholders.push(ch);
			return "\u0000" + (placeholders.length - 1) + "\u0000";
		}
		var escaped = text;
		if (opts.escapeChars) {
			escaped = escaped.replace(/\\(.)/g, function (m, ch) {
				return opts.escapeChars.indexOf(ch) >= 0 ? stash(ch) : m;
			});
		}

		// Extract code blocks and inline code FIRST so their internals aren't
		// interpreted as other markdown.
		var codeBlocks = [];
		escaped = escaped.replace(/```(\w*)\n?([\s\S]*?)```/g, function (m, lang, body) {
			codeBlocks.push({ lang: lang, body: body });
			return "\u0001" + (codeBlocks.length - 1) + "\u0001";
		});
		var inlineCodes = [];
		escaped = escaped.replace(/`([^`\n]+)`/g, function (m, body) {
			inlineCodes.push(body);
			return "\u0002" + (inlineCodes.length - 1) + "\u0002";
		});

		// HTML-escape everything remaining (no tags survive markdown modes)
		escaped = escaped.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

		// Links: [text](url)
		escaped = escaped.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (m, label, url) {
			if (!isSafeUrl(url)) return m;
			urls.push(url);
			return '<a href="' + url.replace(/"/g, "&quot;") + '">' + label + "</a>";
		});

		// Spoiler (V2 only)
		if (opts.spoiler) {
			escaped = escaped.replace(/\|\|([^|]+)\|\|/g, "<tg-spoiler>$1</tg-spoiler>");
		}
		// Strike (V2 only)
		if (opts.strike) {
			escaped = escaped.replace(/~([^~]+)~/g, "<s>$1</s>");
		}
		// Underline (V2 only) — must run before bold/italic to avoid __ being parsed as bold+italic
		if (opts.underline) {
			escaped = escaped.replace(/__([^_]+)__/g, "<u>$1</u>");
		}
		// Bold
		escaped = escaped.replace(/\*([^*\n]+)\*/g, "<b>$1</b>");
		// Italic
		escaped = escaped.replace(/(^|[^_])_([^_\n]+)_(?!_)/g, "$1<i>$2</i>");

		// Blockquote (V2): lines starting with >
		if (opts.blockquote) {
			escaped = escaped.replace(/(^|\n)((?:&gt;[^\n]*(?:\n|$))+)/g, function (m, pre, block) {
				var inner = block.replace(/(^|\n)&gt;\s?/g, "$1").replace(/\n$/, "");
				return pre + "<blockquote>" + inner + "</blockquote>";
			});
		}

		// Re-insert inline code
		escaped = escaped.replace(/\u0002(\d+)\u0002/g, function (m, n) {
			return "<code>" + escapeForCode(inlineCodes[+n]) + "</code>";
		});
		// Re-insert code blocks
		escaped = escaped.replace(/\u0001(\d+)\u0001/g, function (m, n) {
			var b = codeBlocks[+n];
			var cls = b.lang ? ' class="language-' + b.lang.replace(/"/g, "") + '"' : "";
			return "<pre><code" + cls + ">" + escapeForCode(b.body) + "</code></pre>";
		});
		// Re-insert literal escaped chars
		escaped = escaped.replace(/\u0000(\d+)\u0000/g, function (m, n) {
			return placeholders[+n]
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;");
		});

		// Bare URL detection for completeness (and to feed media extractor)
		var bareUrlRe = /(^|[\s>])(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g;
		escaped = escaped.replace(bareUrlRe, function (m, lead, url) {
			urls.push(url);
			return lead + '<a href="' + url.replace(/"/g, "&quot;") + '">' + url + "</a>";
		});

		return { html: escaped, urls: urls, entities: null };

		function escapeForCode(s) {
			return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
		}
	},

	/**
	 * Pick out URLs that look like audio/video by extension, preferring
	 * URLs found inside parsed entities but falling back to bare-URL scan
	 * of the original text if scanBareUrls is enabled.
	 * @method extractMediaUrls
	 */
	extractMediaUrls: function (entityUrls, rawText) {
		var state = this.state;
		var seen = {};
		var results = [];

		function classify(url) {
			var clean = url.split("#")[0].split("?")[0];
			var m = clean.match(/\.([a-z0-9]+)$/i);
			if (!m) return null;
			var ext = m[1].toLowerCase();
			if (state.mediaExtensions.audio.indexOf(ext) >= 0) return "audio";
			if (state.mediaExtensions.video.indexOf(ext) >= 0) return "video";
			return null;
		}

		(entityUrls || []).forEach(function (url) {
			var kind = classify(url);
			if (!kind || seen[url]) return;
			seen[url] = 1;
			results.push({ url: url, kind: kind });
		});

		if (state.scanBareUrls && rawText) {
			var re = /https?:\/\/[^\s<>"']+/g;
			var m;
			while ((m = re.exec(rawText))) {
				var url = m[0].replace(/[.,;:!?)]+$/, "");
				if (seen[url]) continue;
				var kind = classify(url);
				if (!kind) continue;
				seen[url] = 1;
				results.push({ url: url, kind: kind });
			}
		}

		return results;
	},

	/**
	 * Append a Streams/audio/preview or Streams/video/preview tool
	 * container into the bubble. The tool activates on next tool activation pass.
	 * @method appendMediaTool
	 */
	appendMediaTool: function ($msgEl, media) {
		var toolName = media.kind === "audio"
			? "Streams/audio/preview"
			: "Streams/video/preview";
		if (!Q.Tool.defined(toolName)) return;

		var $bubble = $(".Streams_chat_bubble", $msgEl);
		if (!$bubble.length) $bubble = $msgEl;

		var el = Q.Tool.setUpElement("div", toolName, {
			url: media.url
		});
		$(el).addClass("Telegram_format_media Telegram_format_media_" + media.kind);
		$bubble.append(el);

		// Activate if we're already in the live DOM; otherwise activation will
		// cascade from the outer chat message's own .activate() call.
		if (document.body.contains(el)) {
			Q.activate(el);
		}
	},

	/**
	 * Render an inline_keyboard grid into the message.
	 * @method appendKeyboard
	 */
	appendKeyboard: function ($msgEl, rows, ctx) {
		var $bubble = $(".Streams_chat_bubble", $msgEl);
		if (!$bubble.length) $bubble = $msgEl;

		var $kb = $('<div class="Telegram_format_keyboard"></div>');
		(rows || []).forEach(function (row) {
			var $row = $('<div class="Telegram_format_keyboard_row"></div>');
			(row || []).forEach(function (btn) {
				if (!btn || !btn.text) return;
				var $b = $('<button type="button" class="Telegram_format_button"></button>');
				$b.text(btn.text);
				// Classify
				var type = null;
				if (btn.url) type = "url";
				else if (btn.callback_data != null) type = "callback";
				else if (btn.switch_inline_query != null) type = "switch_inline";
				else if (btn.switch_inline_query_current_chat != null) type = "switch_inline_current";
				else if (btn.web_app && btn.web_app.url) type = "web_app";
				else if (btn.login_url && btn.login_url.url) type = "login";
				if (!type) return;
				$b.attr("data-type", type);
				$b.data("telegramButton", btn);
				if (ctx.ordinal) $b.attr("data-ordinal", ctx.ordinal);
				$row.append($b);
			});
			if ($row.children().length) $kb.append($row);
		});
		if ($kb.children().length) $bubble.append($kb);
	},

	/**
	 * Dispatch a button tap to the right handler.
	 * @method handleButtonTap
	 */
	handleButtonTap: function ($btn) {
		var tool = this;
		var state = tool.state;
		var chatState = tool.chatTool.state;
		var btn = $btn.data("telegramButton");
		if (!btn) return;
		var type = $btn.attr("data-type");
		var ordinal = $btn.attr("data-ordinal");

		Q.handle(state.onButtonTap, tool, [{ type: type, button: btn }, $btn.closest(".Streams_chat_item")]);

		switch (type) {
			case "url":
				window.open(btn.url, "_blank", "noopener");
				break;

			case "web_app":
				// Telegram opens Web Apps in a special sheet; we open in new tab.
				// Host app can override via onButtonTap.
				window.open(btn.web_app.url, "_blank", "noopener");
				break;

			case "login":
				window.open(btn.login_url.url, "_blank", "noopener");
				break;

			case "switch_inline":
			case "switch_inline_current":
				// Telegram's switch_inline_query IS the prefill: tapping the
				// button puts "@botusername <query> " into the composer with
				// the cursor at the end, letting the user continue typing or
				// submit as-is.
				var q = btn.switch_inline_query != null
					? btn.switch_inline_query
					: btn.switch_inline_query_current_chat;
				var prefill = "@" + state.botUsername
					+ (q ? " " + q : "") + " ";
				var $input = chatState.$inputElement;
				if ($input && $input.length) {
					$input.val(prefill).trigger("Q_refresh").focus();
					// Place caret at end so user can keep typing
					var inp = $input[0];
					if (inp && inp.setSelectionRange) {
						var pos = prefill.length;
						try { inp.setSelectionRange(pos, pos); } catch (e) {}
					}
				}
				break;

			case "callback":
				$btn.addClass("Q_working");
				Q.Streams.Message.post({
					publisherId: chatState.publisherId,
					streamName: chatState.streamName,
					type: state.callbackMessageType,
					instructions: {
						"Telegram/format": {
							callback_data: btn.callback_data,
							source_ordinal: ordinal ? parseInt(ordinal, 10) : null,
							button_text: btn.text
						}
					}
				}, function (err, message) {
					$btn.removeClass("Q_working");
					if (err) {
						return Q.handle(chatState.onError, tool.chatTool, [err]);
					}
					Q.handle(state.onCallback, tool, [{
						callback_data: btn.callback_data,
						button: btn,
						message: message
					}, $btn.closest(".Streams_chat_item")]);
				});
				break;
		}
	}
});

// Shared URL safety check (http/https/mailto/tg only — no javascript: etc.)
function isSafeUrl(url) {
	if (!url) return false;
	url = String(url).trim();
	return /^(https?:|mailto:|tg:)/i.test(url) && !/[\u0000-\u001f]/.test(url);
}

})(Q, Q.jQuery, window);