/**
 * MMI AJAX Handler
 * 
 * Shared AJAX utilities for all MMI plugins
 * 
 * @package MMI_Hub
 * @since 1.0.7
 */

(function($, mmiGlobal) {
    'use strict';
    
    if (typeof mmiGlobal === 'undefined') {
        console.error('MMI AJAX Handler: mmiGlobal is not defined');
        return;
    }
    
    /**
     * MMI AJAX namespace
     */
    window.MMI = window.MMI || {};
    
    /**
     * Execute AJAX request with standard error handling
     * 
     * @param {string} action - WordPress AJAX action name
     * @param {object} data - Data to send
     * @param {object} options - Additional options (onSuccess, onError, onComplete)
     * @returns {jqXHR}
     */
    window.MMI.ajax = function(action, data, options) {
        options = options || {};
        
        const ajaxData = $.extend({
            action: action,
            nonce: mmiGlobal.nonce
        }, data);
        
        return $.ajax({
            url: mmiGlobal.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            beforeSend: function() {
                if (typeof options.onBeforeSend === 'function') {
                    options.onBeforeSend();
                }
            },
            success: function(response) {
                if (response.success) {
                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(response.data);
                    }
                } else {
                    const errorMsg = response.data || 'Unknown error occurred';
                    console.error('MMI AJAX Error:', errorMsg);
                    if (typeof options.onError === 'function') {
                        options.onError(errorMsg);
                    } else {
                        alert('Error: ' + errorMsg);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('MMI AJAX Request Failed:', status, error);
                const errorMsg = 'Request failed: ' + (error || status);
                if (typeof options.onError === 'function') {
                    options.onError(errorMsg);
                } else {
                    alert(errorMsg);
                }
            },
            complete: function() {
                if (typeof options.onComplete === 'function') {
                    options.onComplete();
                }
            }
        });
    };
    
    /**
     * Show status message in a container
     * 
     * @param {jQuery|string} container - Container element or selector
     * @param {string} message - Message to display
     * @param {string} type - Message type (running, success, error, info)
     */
    window.MMI.showStatus = function(container, message, type) {
        const $container = typeof container === 'string' ? $(container) : container;
        
        $container
            .removeClass('running success error info')
            .addClass(type)
            .html(message)
            .show();
    };
    
    /**
     * Hide status message
     * 
     * @param {jQuery|string} container - Container element or selector
     */
    window.MMI.hideStatus = function(container) {
        const $container = typeof container === 'string' ? $(container) : container;
        $container.hide().removeClass('running success error info').html('');
    };
    
    /**
     * Format time duration
     * 
     * @param {number} seconds - Seconds
     * @returns {string}
     */
    window.MMI.formatDuration = function(seconds) {
        if (seconds < 60) {
            return seconds + 's';
        }
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return minutes + 'm ' + secs + 's';
    };
    
    /**
     * Format number with commas
     * 
     * @param {number} num - Number to format
     * @returns {string}
     */
    window.MMI.formatNumber = function(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    };
    
    /**
     * Debounce function
     * 
     * @param {function} func - Function to debounce
     * @param {number} wait - Wait time in ms
     * @returns {function}
     */
    window.MMI.debounce = function(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    };
    
    console.log('MMI AJAX Handler initialized - Version ' + mmiGlobal.version);
    
})(jQuery, window.mmiGlobal || {});
