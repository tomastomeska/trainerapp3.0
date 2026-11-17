'use strict';

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function (error) {
            console.error('TrainerApp service worker registration failed.', error);
        });
    });
}
