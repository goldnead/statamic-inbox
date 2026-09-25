/**
 * Turning a stored message into what the thread shows.
 *
 * The HTML arrives sanitised by the server (HtmlCleaner): no scripts, remote
 * images parked in `data-inbox-src`, inline images in `data-inbox-cid`. What
 * is left to do here is presentation, and it happens in a detached document
 * that never touches the Control Panel's DOM:
 *
 *   - inline images get the permission-checked attachment URL from the
 *     message's `inline_images` map (a `cid:` means nothing to a browser);
 *   - remote images get their `src` back only after "Bilder laden";
 *   - quoted history (Gmail, Apple Mail, Outlook, Thunderbird) is hidden until
 *     someone asks for it;
 *   - a Content-Security-Policy inside the frame blocks every remote load the
 *     rewrite did not catch, until images are allowed.
 */

export const REMOTE_ATTRIBUTE = 'data-inbox-src';
export const CID_ATTRIBUTE = 'data-inbox-cid';

/** Where mail programs put the quoted history. */
export const QUOTE_SELECTORS = [
    '.gmail_quote',
    'blockquote[type="cite"]',
    '.yahoo_quoted',
    '.moz-cite-prefix',
    '#appendonsend ~ *',
    '#divRplyFwdMsg',
    '#divRplyFwdMsg ~ *',
];

/** "Am So., 20. Sept. 2026 um 14:00 Uhr schrieb …:", "On …, … wrote:" */
const ATTRIBUTION = /(schrieb|wrote|a écrit|escribió|ha scritto)\s*[^:]*:\s*$/iu;

/**
 * @param {string} html  html_sanitized
 * @param {{ inlineImages?: Record<string,string>, loadRemote?: boolean, showQuoted?: boolean, origin?: string, font?: string }} options
 * @returns {{ srcdoc: string, hasQuote: boolean }}
 */
export function buildSrcdoc(html, { inlineImages = {}, loadRemote = false, showQuoted = false, origin = '', font = '' } = {}) {
    const doc = new DOMParser().parseFromString(`<!doctype html><html><head></head><body>${html ?? ''}</body></html>`, 'text/html');

    doc.querySelectorAll(`img[${CID_ATTRIBUTE}]`).forEach((img) => {
        const url = inlineImages[img.getAttribute(CID_ATTRIBUTE)];
        if (url) img.setAttribute('src', url);
    });

    if (loadRemote) {
        doc.querySelectorAll(`[${REMOTE_ATTRIBUTE}]`).forEach((el) => {
            el.setAttribute('src', el.getAttribute(REMOTE_ATTRIBUTE));
            el.removeAttribute(REMOTE_ATTRIBUTE);
        });
    }

    // The sanitiser drops `class`, so Gmail's `.gmail_quote` is gone by the
    // time the HTML arrives. What survives is the shape: a blockquote, usually
    // behind a line like "Am … schrieb …:". Both are marked and hidden.
    doc.querySelectorAll(QUOTE_SELECTORS.join(',')).forEach((el) => el.setAttribute('data-inbox-quote', ''));
    // Outermost blockquotes only; nested ones go with their parent.
    doc.querySelectorAll('blockquote:not(blockquote blockquote)').forEach((quote) => {
        quote.setAttribute('data-inbox-quote', '');
        const attribution = quote.previousElementSibling;
        if (attribution && ATTRIBUTION.test(attribution.textContent ?? '')) {
            attribution.setAttribute('data-inbox-quote', '');
        }
    });

    const selector = '[data-inbox-quote]';
    const hasQuote = doc.querySelector(selector) !== null;

    const images = ['data:', origin, loadRemote ? 'https: http:' : ''].filter(Boolean).join(' ');
    const csp = `default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src ${images}`;

    const head = doc.head;
    head.insertAdjacentHTML('afterbegin', [
        `<meta http-equiv="Content-Security-Policy" content="${csp}">`,
        '<meta charset="utf-8">',
        '<base target="_blank">',
        '<style>',
        ':root{color-scheme:light}',
        // The Control Panel's own font, so an HTML mail reads like a text one.
        `html,body{margin:0;padding:0;background:#fff;color:#1f2937;font-size:14px;line-height:1.5;font-family:${(font || 'system-ui,sans-serif').replace(/[<>{}]/g, '')};overflow-wrap:anywhere}`,
        'blockquote{margin:0 0 0 .5rem;padding-left:.75rem;border-left:2px solid #d1d5db;color:#4b5563}',
        'img{max-width:100%;height:auto}',
        'table{max-width:100%}',
        'pre{white-space:pre-wrap}',
        showQuoted ? '' : `${selector}{display:none!important}`,
        '</style>',
    ].join(''));

    return { srcdoc: `<!doctype html>${doc.documentElement.outerHTML}`, hasQuote };
}

/**
 * Plain text split into what was written and what was quoted.
 *
 * The server stores `body_stripped` (the text without quoted replies and
 * signature separators). When it is shorter than the full text, the rest is
 * the quoted history.
 */
export function splitText(text, stripped) {
    const full = (text ?? '').replace(/\s+$/u, '');
    const main = (stripped ?? '').replace(/\s+$/u, '');

    if (main === '' || main === full) {
        return { main: full, full, hasQuote: false };
    }

    return { main, full, hasQuote: full.length > main.length };
}

/** Attachments to list below the message: inline images shown in the HTML are not repeated. */
export function listedAttachments(message) {
    const html = message?.html_sanitized ?? '';
    // Parsed, not searched: the sanitiser writes `@` as `&#64;`.
    const shown = new Set();
    if (html) {
        new DOMParser().parseFromString(html, 'text/html')
            .querySelectorAll(`[${CID_ATTRIBUTE}]`)
            .forEach((img) => shown.add(img.getAttribute(CID_ATTRIBUTE)));
    }

    return (message?.attachments ?? []).filter((a) => !a.content_id || !shown.has(a.content_id));
}

/**
 * Which messages start expanded: the newest, and every one carrying an
 * error, so a failed send is never folded away.
 */
export function initiallyExpanded(messages) {
    const ids = new Set();
    const list = messages ?? [];
    if (list.length) ids.add(list[list.length - 1].id);
    list.forEach((m) => {
        if (m.send_error || m.filed_error) ids.add(m.id);
    });

    return ids;
}
