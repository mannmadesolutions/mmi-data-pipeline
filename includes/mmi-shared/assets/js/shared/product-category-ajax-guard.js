/**
 * Product category add-tag AJAX guard.
 *
 * WooCommerce reparses the add-tag response via `request.responseXML` inside an
 * `ajaxComplete` handler on the product category admin screen. If another plugin
 * pollutes the XML response or the browser exposes it as plain text, that second
 * parse call throws before the admin UI can finish its update cycle.
 *
 * This guard only runs on the WooCommerce `product_cat` taxonomy screen. It
 * normalizes add-tag responses by recovering the embedded XML payload from raw
 * response text and patching `wpAjax.parseAjaxResponse()` to consume that
 * recovered document whenever core or WooCommerce passes a broken payload.
 */
(function ($, window) {
    'use strict';

    /* ── Screen + request constants ─────────────────────────────────────── */
    const TAXONOMY = 'product_cat';
    const POST_TYPE = 'product';
    const ADD_TAG_ACTION = 'action=add-tag';
    const XML_MIME_TYPE = 'text/xml';
    const XML_DECLARATION = '<?xml';
    const XML_ROOT_TAG = '<wp_ajax';
    const XML_CLOSE_TAG = '</wp_ajax>';

    /* ── Cached response state ──────────────────────────────────────────── */
    let lastAddTagXmlDocument = null;
    let lastAddTagResponseText = '';

    /**
     * Restrict the guard to the WooCommerce product category admin screen.
     *
     * @returns {boolean}
     */
    function isProductCategoryScreen() {
        const url = new URL(window.location.href);

        return url.pathname.indexOf('edit-tags.php') !== -1
            && url.searchParams.get('taxonomy') === TAXONOMY
            && url.searchParams.get('post_type') === POST_TYPE;
    }

    /**
     * Check whether the AJAX request is the taxonomy add-tag request.
     *
     * @param {string | undefined} requestData
     * @returns {boolean}
     */
    function isAddTagRequest(requestData) {
        return typeof requestData === 'string' && requestData.indexOf(ADD_TAG_ACTION) !== -1;
    }

    /**
     * Attempt to isolate the WP_Ajax_Response XML payload from a noisy response body.
     *
     * @param {string} responseText
     * @returns {string}
     */
    function extractXmlPayload(responseText) {
        const xmlStart = responseText.indexOf(XML_DECLARATION);
        const rootStart = responseText.indexOf(XML_ROOT_TAG);
        const payloadStart = xmlStart !== -1 ? xmlStart : rootStart;
        const payloadEnd = responseText.lastIndexOf(XML_CLOSE_TAG);

        if (payloadStart === -1 || payloadEnd === -1) {
            return '';
        }

        return responseText.slice(payloadStart, payloadEnd + XML_CLOSE_TAG.length);
    }

    /**
     * Parse an XML string and reject parser errors.
     *
     * @param {string} xmlString
     * @returns {Document | null}
     */
    function parseXmlDocument(xmlString) {
        if (!xmlString) {
            return null;
        }

        const parser = new window.DOMParser();
        const parsedDocument = parser.parseFromString(xmlString, XML_MIME_TYPE);

        return parsedDocument.querySelector('parsererror') ? null : parsedDocument;
    }

    /**
     * Convert a broken add-tag response into a usable XML document when possible.
     *
     * @param {unknown} response
     * @returns {unknown}
     */
    function normalizeAjaxResponse(response) {
        if (response && typeof response === 'object' && typeof response.getElementsByTagName === 'function') {
            return response;
        }

        if (typeof response === 'string') {
            const parsedDocument = parseXmlDocument(extractXmlPayload(response));

            if (parsedDocument) {
                lastAddTagXmlDocument = parsedDocument;
                lastAddTagResponseText = response;

                return parsedDocument;
            }
        }

        if (response == null && lastAddTagXmlDocument) {
            return lastAddTagXmlDocument;
        }

        return response;
    }

    /**
     * Cache the most recent add-tag response so later parsers can recover it.
     *
     * @param {JQuery.jqXHR} jqXHR
     */
    function cacheAddTagResponse(jqXHR) {
        if (!jqXHR || typeof jqXHR.responseText !== 'string' || !jqXHR.responseText) {
            return;
        }

        lastAddTagResponseText = jqXHR.responseText;

        if (jqXHR.responseXML && typeof jqXHR.responseXML.getElementsByTagName === 'function') {
            lastAddTagXmlDocument = jqXHR.responseXML;
            return;
        }

        lastAddTagXmlDocument = parseXmlDocument(extractXmlPayload(jqXHR.responseText));
    }

    /**
     * Patch the core AJAX response parser once so it can recover XML payloads.
     */
    function patchAjaxResponseParser() {
        if (!window.wpAjax || typeof window.wpAjax.parseAjaxResponse !== 'function') {
            return;
        }

        if (window.wpAjax.parseAjaxResponse.__mmiProductCategoryGuardApplied) {
            return;
        }

        const originalParseAjaxResponse = window.wpAjax.parseAjaxResponse;

        window.wpAjax.parseAjaxResponse = function patchedParseAjaxResponse(response, responseElement, formId) {
            const normalizedResponse = normalizeAjaxResponse(response);

            return originalParseAjaxResponse.call(window.wpAjax, normalizedResponse, responseElement, formId);
        };

        window.wpAjax.parseAjaxResponse.__mmiProductCategoryGuardApplied = true;
    }

    if (!isProductCategoryScreen()) {
        return;
    }

    patchAjaxResponseParser();

    $(document).ajaxSuccess(function (_event, jqXHR, settings) {
        if (!settings || !isAddTagRequest(settings.data)) {
            return;
        }

        cacheAddTagResponse(jqXHR);
    });
}(jQuery, window));